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
 * A PayPal capture names an agreement or an order and nothing else — no payer
 * email, no name. The donor therefore always costs an API call, which is the
 * difference from the Razorpay path.
 */
class ForeignPayPalAdoptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();

        config([
            'payments.paypal.client_id' => 'client',
            'payments.paypal.secret' => 'secret',
            'payments.paypal.mode' => 'sandbox',
        ]);
    }

    /** PAYMENT.SALE.COMPLETED — what a recurring PayPal charge actually sends. */
    private function saleBody(array $overrides = []): array
    {
        return ['event_type' => 'PAYMENT.SALE.COMPLETED', 'resource' => array_merge([
            'id' => '9XY55555AB1234567',
            'billing_agreement_id' => 'I-LEGACYSUB001',
            'amount' => ['total' => '12.00', 'currency' => 'USD'],
            'state' => 'completed',
        ], $overrides)];
    }

    private function fakePayPalLookups(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v1/billing/subscriptions/I-LEGACYSUB001' => Http::response([
                'id' => 'I-LEGACYSUB001',
                'plan_id' => 'P-LEGACY0001',
                'status' => 'ACTIVE',
                'subscriber' => [
                    'email_address' => 'anita@example.com',
                    'name' => ['given_name' => 'Anita', 'surname' => 'Desai'],
                ],
            ]),
            'api-m.sandbox.paypal.com/v1/billing/plans/P-LEGACY0001' => Http::response([
                'id' => 'P-LEGACY0001',
                'name' => 'Gaushala Supporter',
                'billing_cycles' => [
                    // A trial cycle first: the REGULAR one is what is charged.
                    ['tenure_type' => 'TRIAL', 'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                        'pricing_scheme' => ['fixed_price' => ['value' => '0.00', 'currency_code' => 'USD']]],
                    ['tenure_type' => 'REGULAR', 'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                        'pricing_scheme' => ['fixed_price' => ['value' => '12.00', 'currency_code' => 'USD']]],
                ],
            ]),
        ]);
    }

    private function record(array $body): ?Transaction
    {
        $gateway = new \App\Payments\Gateways\PayPalGateway;
        $request = \Illuminate\Http\Request::create('/webhooks/paypal', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body));

        return app(PaymentRecorder::class)->recordSuccess('paypal', $gateway->parseWebhook($request));
    }

    public function test_a_recurring_paypal_charge_is_adopted_with_its_donor(): void
    {
        $this->fakePayPalLookups();

        $txn = $this->record($this->saleBody());

        $this->assertNotNull($txn);
        $this->assertSame('paid', $txn->status);
        $this->assertSame('Anita Desai', $txn->donor_name);
        $this->assertSame('anita@example.com', $txn->donor_email);
        $this->assertSame(1200, $txn->amount, 'USD minor units');
        $this->assertSame('USD', $txn->currency);
        $this->assertNotNull($txn->receipt_no);

        $subscription = Subscription::firstWhere('gateway_subscription_id', 'I-LEGACYSUB001');
        $this->assertNotNull($subscription);
        $this->assertSame($subscription->id, $txn->subscription_id);

        Queue::assertPushed(LinkOrCreateDonorAccount::class);
    }

    public function test_the_regular_cycle_sets_the_mirrored_plans_price_not_the_trial(): void
    {
        $this->fakePayPalLookups();

        $this->record($this->saleBody());

        $plan = Plan::firstWhere('name', 'Gaushala Supporter');
        $this->assertNotNull($plan);
        $this->assertSame(1200, $plan->amount, 'the REGULAR cycle, not the 0.00 trial');
        $this->assertSame('USD', $plan->currency);
        $this->assertSame('monthly', $plan->interval);
        $this->assertFalse($plan->is_active);
    }

    public function test_an_existing_donor_is_linked(): void
    {
        $this->fakePayPalLookups();
        $user = User::create([
            'name' => 'Anita Desai', 'email' => 'anita@example.com', 'password' => 'secret123',
        ]);

        $txn = $this->record($this->saleBody());

        $this->assertSame($user->id, $txn->user_id);
        $this->assertSame(1, User::count());
    }

    public function test_repeated_deliveries_record_one_payment(): void
    {
        $this->fakePayPalLookups();

        $first = $this->record($this->saleBody());
        $second = $this->record($this->saleBody());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, Subscription::count());
    }

    public function test_a_second_charge_on_the_same_subscription_is_a_second_transaction(): void
    {
        $this->fakePayPalLookups();

        $first = $this->record($this->saleBody());
        $second = $this->record($this->saleBody(['id' => '9XY66666CD7654321']));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Transaction::count());
        $this->assertSame(1, Subscription::count(), 'one subscription, two charges');
        $this->assertSame($first->subscription_id, $second->subscription_id);
    }

    public function test_a_one_off_capture_takes_the_payer_from_the_order(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/checkout/orders/5AB12345CD678901E' => Http::response([
                'id' => '5AB12345CD678901E',
                'payer' => [
                    'email_address' => 'walker@example.com',
                    'name' => ['given_name' => 'Sam', 'surname' => 'Walker'],
                ],
                'purchase_units' => [['description' => 'Ambulance Fund']],
            ]),
        ]);

        $txn = $this->record(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => '3CD99999EF1112223',
            'amount' => ['value' => '40.00', 'currency_code' => 'USD'],
            'supplementary_data' => ['related_ids' => ['order_id' => '5AB12345CD678901E']],
        ]]);

        $this->assertNotNull($txn);
        $this->assertSame('one_off', $txn->type);
        $this->assertSame('Sam Walker', $txn->donor_name);
        $this->assertSame('walker@example.com', $txn->donor_email);
        $this->assertSame('Ambulance Fund', $txn->purpose);
        $this->assertSame(4000, $txn->amount);
        $this->assertSame(0, Subscription::count());
    }
}
