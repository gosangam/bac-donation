<?php

namespace Tests\Feature;

use App\Jobs\LinkOrCreateDonorAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Subscriptions that predate this dashboard — or were created directly in a
 * gateway's console — keep charging. Their webhooks reference nothing local, so
 * before adoption they were logged and dropped: the donor's money arrived, and
 * no transaction, receipt or account ever came of it.
 */
class ForeignSubscriptionAdoptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();

        config([
            'payments.razorpay.key_id' => 'rzp_live_x',
            'payments.razorpay.key_secret' => 'secret',
            'payments.razorpay.webhook_secret' => 'whsec',
            'payments.paypal.client_id' => 'client',
            'payments.paypal.secret' => 'secret',
            'payments.paypal.mode' => 'sandbox',
        ]);
    }

    /** A real payment.captured for a subscription charge: no subscription id, an invoice id. */
    private function razorpayRenewalBody(array $paymentOverrides = []): array
    {
        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => array_merge([
            'id' => 'pay_FOREIGN0001',
            'amount' => 100000,
            'currency' => 'INR',
            'status' => 'captured',
            'order_id' => null,
            'invoice_id' => 'inv_OLDSUB01',
            'method' => 'upi',
            'vpa' => 'meera@okhdfc',
            'email' => 'meera@example.com',
            'contact' => '+919812345678',
            'notes' => [],
        ], $paymentOverrides)]]];
    }

    /** Razorpay: payment → invoice → subscription → plan. */
    private function fakeRazorpayLookups(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.razorpay.com/v1/invoices/inv_OLDSUB01' => Http::response([
                'id' => 'inv_OLDSUB01',
                'subscription_id' => 'sub_LEGACY001',
            ]),
            'api.razorpay.com/v1/subscriptions/sub_LEGACY001' => Http::response([
                'id' => 'sub_LEGACY001',
                'plan_id' => 'plan_LEGACY001',
                'status' => 'active',
            ]),
            'api.razorpay.com/v1/plans/plan_LEGACY001' => Http::response([
                'id' => 'plan_LEGACY001',
                'period' => 'monthly',
                'interval' => 1,
                'item' => ['name' => 'Gaushala Supporter', 'amount' => 100000, 'currency' => 'INR'],
            ]),
        ], $overrides));
    }

    private function record(array $body): ?Transaction
    {
        $gateway = new \App\Payments\Gateways\RazorpayGateway;
        $request = \Illuminate\Http\Request::create('/webhooks/razorpay', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body));

        return app(PaymentRecorder::class)->recordSuccess('razorpay', $gateway->parseWebhook($request));
    }

    public function test_a_charge_on_an_unknown_subscription_is_adopted(): void
    {
        $this->fakeRazorpayLookups();

        $txn = $this->record($this->razorpayRenewalBody());

        $this->assertNotNull($txn, 'the payment must not be dropped');
        $this->assertSame('paid', $txn->status);
        $this->assertSame('subscription', $txn->type);
        $this->assertSame(100000, $txn->amount);
        $this->assertSame('meera@example.com', $txn->donor_email);
        $this->assertSame('+919812345678', $txn->donor_phone);
        $this->assertNotNull($txn->receipt_no, 'an adopted payment still earns a receipt');

        $subscription = Subscription::firstWhere('gateway_subscription_id', 'sub_LEGACY001');
        $this->assertNotNull($subscription, 'the gateway-side subscription must be mirrored');
        $this->assertSame($subscription->id, $txn->subscription_id);
        $this->assertSame('active', $subscription->status);
    }

    public function test_the_donor_gets_an_account(): void
    {
        $this->fakeRazorpayLookups();

        $txn = $this->record($this->razorpayRenewalBody());

        $this->assertNull($txn->user_id, 'no account exists yet');
        Queue::assertPushed(LinkOrCreateDonorAccount::class,
            fn ($job) => $job->transaction->is($txn));
    }

    public function test_an_existing_donor_is_linked_rather_than_duplicated(): void
    {
        $this->fakeRazorpayLookups();
        $user = User::create([
            'name' => 'Meera Iyer', 'email' => 'Meera@Example.com', 'password' => 'secret123',
        ]);

        $txn = $this->record($this->razorpayRenewalBody());

        $this->assertSame($user->id, $txn->user_id, 'matched on email, case-insensitively');
        $this->assertSame($user->id, Subscription::firstWhere('gateway_subscription_id', 'sub_LEGACY001')->user_id);
        $this->assertSame(1, User::count());
    }

    public function test_an_unmapped_gateway_plan_is_mirrored_but_never_offered(): void
    {
        $this->fakeRazorpayLookups();

        $this->record($this->razorpayRenewalBody());

        $plan = Plan::firstWhere('name', 'Gaushala Supporter');
        $this->assertNotNull($plan);
        $this->assertFalse($plan->is_active, 'an imported plan must not appear to new donors');
        $this->assertSame('plan_LEGACY001', $plan->gatewayPlanId('razorpay'));
        $this->assertSame(100000, $plan->amount);
        $this->assertSame('monthly', $plan->interval);
    }

    public function test_a_plan_already_mapped_locally_is_reused(): void
    {
        $this->fakeRazorpayLookups();
        $existing = Plan::create([
            'slug' => 'monthly-1000', 'name' => 'Gaushala Supporter',
            'amount' => 100000, 'currency' => 'INR',
            'interval' => 'monthly', 'interval_count' => 1,
            'gateway_plan_ids' => ['razorpay' => 'plan_LEGACY001'], 'is_active' => true,
        ]);

        $txn = $this->record($this->razorpayRenewalBody());

        $this->assertSame(1, Plan::count(), 'must not mirror a plan we already map');
        $this->assertSame($existing->id, $txn->subscription->plan_id);
    }

    public function test_adoption_is_idempotent_across_webhook_retries(): void
    {
        $this->fakeRazorpayLookups();

        $first = $this->record($this->razorpayRenewalBody());
        $second = $this->record($this->razorpayRenewalBody());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, Subscription::count());
        $this->assertSame($first->receipt_no, $second->fresh()->receipt_no);
    }

    /**
     * The swallowed-renewal bug. Razorpay copies a subscription's notes onto
     * every renewal payment, so each charge arrives quoting the FIRST
     * transaction's reference. Matching on that alone returned a row already
     * paid, and the renewal was discarded as a duplicate.
     */
    public function test_a_renewal_quoting_the_first_charges_reference_is_not_swallowed(): void
    {
        $this->fakeRazorpayLookups();

        $user = User::create(['name' => 'Meera', 'email' => 'meera@example.com', 'password' => 'secret123']);
        $plan = Plan::create([
            'slug' => 'monthly-1000', 'name' => 'Gaushala Supporter',
            'amount' => 100000, 'currency' => 'INR', 'interval' => 'monthly', 'interval_count' => 1,
            'gateway_plan_ids' => ['razorpay' => 'plan_LEGACY001'], 'is_active' => true,
        ]);
        $subscription = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'gateway' => 'razorpay',
            'gateway_subscription_id' => 'sub_LEGACY001', 'status' => 'active',
        ]);
        $first = Transaction::create([
            'user_id' => $user->id, 'subscription_id' => $subscription->id,
            'gateway' => 'razorpay', 'type' => 'subscription',
            'reference' => 'BAC-FIRSTCHARGE', 'gateway_payment_id' => 'pay_FIRSTCHARGE',
            'gateway_order_id' => 'sub_LEGACY001',
            'amount' => 100000, 'currency' => 'INR', 'status' => 'paid', 'paid_at' => now(),
            'donor_name' => 'Meera', 'donor_email' => 'meera@example.com',
        ]);

        // Month two: a new payment id, but the same reference in the notes.
        $second = $this->record($this->razorpayRenewalBody([
            'id' => 'pay_SECONDCHARGE',
            'notes' => ['reference' => 'BAC-FIRSTCHARGE', 'donor_name' => 'Meera'],
        ]));

        $this->assertNotNull($second, 'the renewal must be recorded, not swallowed');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('pay_SECONDCHARGE', $second->gateway_payment_id);
        $this->assertSame($subscription->id, $second->subscription_id);
        $this->assertSame($user->id, $second->user_id);
        $this->assertNotSame($first->receipt_no, $second->receipt_no, 'two charges, two receipts');
        $this->assertSame(2, Transaction::count());
        $this->assertSame(1, Subscription::count(), 'the known subscription is reused');
    }

    public function test_a_one_off_taken_outside_the_dashboard_is_adopted(): void
    {
        Http::fake();   // a one-off needs no lookup: the payload names the donor

        $txn = $this->record($this->razorpayRenewalBody([
            'id' => 'pay_PAYMENTLINK',
            'invoice_id' => null,
            'notes' => ['purpose' => 'Ambulance Fund'],
        ]));

        $this->assertNotNull($txn);
        $this->assertSame('one_off', $txn->type);
        $this->assertSame('Ambulance Fund', $txn->purpose);
        $this->assertNull($txn->subscription_id);
        $this->assertSame(0, Subscription::count());
    }

    public function test_razorpays_placeholder_email_never_becomes_an_account(): void
    {
        $this->fakeRazorpayLookups();

        $txn = $this->record($this->razorpayRenewalBody(['email' => 'void@razorpay.com']));

        $this->assertNotNull($txn, 'the money is still recorded');
        $this->assertSame('', $txn->donor_email, 'but not under a placeholder address');
    }

    /**
     * Taken from the real payload for pay_TWmDGUvRWChWEV: Razorpay sends
     * card.name as "" rather than omitting it, and this app's own checkout
     * leaves the donor's address in the payment notes.
     */
    public function test_a_blank_card_name_does_not_beat_the_fallback(): void
    {
        $this->fakeRazorpayLookups();

        $txn = $this->record($this->razorpayRenewalBody([
            'method' => 'card',
            'card' => ['name' => '', 'last4' => '4366', 'network' => 'Visa', 'type' => 'credit'],
            'notes' => ['address' => 'ABC Street, Delhi, Delhi, 110033, IN'],
        ]));

        $this->assertSame('Donor', $txn->donor_name, 'an empty string is not a name');
        $this->assertSame('ABC Street, Delhi, Delhi, 110033, IN', $txn->donor_address);
        $this->assertSame('Visa credit ••••4366', $txn->method);
    }

    public function test_adoption_can_be_turned_off(): void
    {
        config(['payments.adopt_unknown_payments' => false]);
        $this->fakeRazorpayLookups();

        $this->assertNull($this->record($this->razorpayRenewalBody()));
        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    /**
     * Razorpay's payment entity already names the donor and the amount, so a
     * failed invoice lookup costs only the link to the subscription. Recording
     * the charge unattached beats dropping money we can see and account for;
     * the next renewal adopts the subscription properly.
     */
    public function test_a_failed_lookup_still_records_the_money(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['error' => 'nope'], 500)]);

        $txn = $this->record($this->razorpayRenewalBody());

        $this->assertNotNull($txn);
        $this->assertSame('paid', $txn->status);
        $this->assertSame('meera@example.com', $txn->donor_email);
        $this->assertSame('subscription', $txn->type, 'the invoice id still marks it recurring');
        $this->assertNull($txn->subscription_id, 'but there is nothing to attach it to');
        $this->assertSame(0, Subscription::count());
    }

    public function test_the_webhook_endpoint_adopts_end_to_end(): void
    {
        $this->fakeRazorpayLookups();
        $body = json_encode($this->razorpayRenewalBody());

        $this->call('POST', '/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec'),
        ], $body)->assertOk();

        $this->assertSame(1, Transaction::where('gateway_payment_id', 'pay_FOREIGN0001')->count());
        $this->assertNotNull(Subscription::firstWhere('gateway_subscription_id', 'sub_LEGACY001'));
    }
}
