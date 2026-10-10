<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\DonorAccountCreated;
use App\Payments\Gateways\StripeGateway;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stripe deliveries posted through the real route, signed as Stripe signs
 * them, with the queue running inline so account linking happens in-test.
 */
class StripeWebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.stripe.secret_key' => 'sk_test_x',
            'payments.stripe.webhook_secret' => self::SECRET,
            // Receipt email is covered by ReceiptEmailTest; here it would only
            // add a PDF render and a logo fetch to every delivery.
            'payments.receipts.email' => false,
        ]);

        Http::preventStrayRequests();
    }

    /** @param  array<string, mixed>  $object */
    private function deliver(string $type, array $object, ?string $secret = self::SECRET): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.md5($type.json_encode($object)), 'type' => $type, 'data' => ['object' => $object]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return $this->call('POST', route('webhooks', 'stripe'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }

    /** A Payment Link checkout: nothing of ours on it, the donor only in customer_details. */
    private function paymentLinkSession(array $overrides = []): array
    {
        return array_merge([
            'object' => 'checkout.session',
            'id' => 'cs_live_PAYLINK01',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_PAYLINK01',
            'client_reference_id' => null,
            'amount_total' => 250000,
            'currency' => 'inr',
            'customer_details' => [
                'email' => 'Gopal@Example.com',
                'name' => 'Gopal Das',
                'phone' => '+919811122233',
                'address' => ['line1' => '4 Loi Bazaar', 'city' => 'Vrindavan', 'postal_code' => '281121', 'country' => 'IN'],
            ],
        ], $overrides);
    }

    public function test_a_payment_from_a_new_email_creates_an_account_links_it_and_sends_a_set_password_email(): void
    {
        Notification::fake();

        $this->deliver('checkout.session.completed', $this->paymentLinkSession())->assertOk();

        $user = User::where('email', 'gopal@example.com')->sole();
        $transaction = Transaction::sole();

        $this->assertSame('Gopal Das', $user->name);
        $this->assertSame($user->id, $transaction->user_id);
        $this->assertSame('paid', $transaction->status);
        $this->assertSame(250000, $transaction->amount);
        $this->assertSame('pi_PAYLINK01', $transaction->gateway_payment_id);
        $this->assertSame('4 Loi Bazaar, Vrindavan, 281121, IN', $transaction->donor_address);
        $this->assertNotNull($transaction->receipt_no);
        Notification::assertSentTo($user, DonorAccountCreated::class);
    }

    public function test_a_payment_from_an_existing_donors_email_is_linked_without_any_email(): void
    {
        Notification::fake();
        $donor = User::factory()->create(['email' => 'gopal@example.com']);

        $this->deliver('checkout.session.completed', $this->paymentLinkSession())->assertOk();

        $this->assertSame($donor->id, Transaction::sole()->user_id);
        $this->assertSame(1, User::count());
        Notification::assertNothingSent();
    }

    public function test_a_replayed_delivery_records_the_payment_once(): void
    {
        Notification::fake();

        $this->deliver('checkout.session.completed', $this->paymentLinkSession())->assertOk();
        $receipt = Transaction::sole()->receipt_no;
        $this->deliver('checkout.session.completed', $this->paymentLinkSession())->assertOk();

        $this->assertSame($receipt, Transaction::sole()->receipt_no);
        Notification::assertSentTimes(DonorAccountCreated::class, 1);
    }

    public function test_an_unsettled_session_records_nothing_until_its_async_payment_succeeds(): void
    {
        Notification::fake();

        $this->deliver('checkout.session.completed', $this->paymentLinkSession(['payment_status' => 'unpaid']))->assertOk();
        $this->assertSame(0, Transaction::count());

        $this->deliver('checkout.session.async_payment_succeeded', $this->paymentLinkSession())->assertOk();
        $this->assertSame('paid', Transaction::sole()->status);
    }

    public function test_an_unsigned_delivery_is_rejected(): void
    {
        $this->deliver('checkout.session.completed', $this->paymentLinkSession(), 'whsec_forged')->assertStatus(400);

        $this->assertSame(0, Transaction::count());
    }

    /**
     * The first subscription charge arrives as both the session and its first
     * invoice. One payment, one receipt — and the subscription must learn its
     * sub_… id, or every renewal would fail to find it.
     */
    public function test_a_dashboard_subscription_is_receipted_once_and_its_renewals_are_linked(): void
    {
        Notification::fake();
        $plan = Plan::create([
            'slug' => 'monthly', 'name' => 'Monthly', 'amount' => 50000, 'currency' => 'INR',
            'interval' => 'monthly', 'interval_count' => 1, 'gateway_plan_ids' => ['stripe' => 'price_MONTHLY'],
        ]);
        $subscription = Subscription::create([
            'plan_id' => $plan->id, 'gateway' => 'stripe', 'gateway_subscription_id' => 'cs_live_SUB01', 'status' => 'pending',
        ]);
        $pending = Transaction::create([
            'gateway' => 'stripe', 'type' => 'subscription', 'subscription_id' => $subscription->id,
            'reference' => 'BAC-STRIPESUB01', 'gateway_order_id' => 'cs_live_SUB01',
            'amount' => 50000, 'currency' => 'INR', 'status' => 'pending',
            'donor_name' => 'Lalita', 'donor_email' => 'lalita@example.com',
        ]);

        $this->deliver('checkout.session.completed', [
            'object' => 'checkout.session', 'id' => 'cs_live_SUB01', 'mode' => 'subscription',
            'payment_status' => 'paid', 'payment_intent' => null, 'invoice' => 'in_FIRST',
            'subscription' => 'sub_LALITA', 'client_reference_id' => 'BAC-STRIPESUB01',
            'amount_total' => 50000, 'currency' => 'inr',
        ])->assertOk();
        // API version 2025-03-31 and later: the subscription sits under `parent`.
        $invoice = fn (string $id, string $reason) => [
            'object' => 'invoice', 'id' => $id, 'billing_reason' => $reason,
            'amount_paid' => 50000, 'currency' => 'inr', 'customer_email' => 'lalita@example.com',
            'parent' => ['subscription_details' => ['subscription' => 'sub_LALITA', 'metadata' => ['reference' => 'BAC-STRIPESUB01']]],
            'lines' => ['data' => [['pricing' => ['price_details' => ['price' => 'price_MONTHLY']]]]],
        ];
        $this->deliver('invoice.paid', $invoice('in_FIRST', 'subscription_create'))->assertOk();

        $this->assertSame(1, Transaction::count(), 'session and first invoice are one payment');
        $this->assertSame('in_FIRST', $pending->fresh()->gateway_payment_id);
        $this->assertSame('sub_LALITA', $subscription->fresh()->gateway_subscription_id);
        $this->assertSame('active', $subscription->fresh()->status);

        Http::fake(['api.stripe.com/v1/prices/price_MONTHLY*' => Http::response(['id' => 'price_MONTHLY'])]);
        $this->deliver('invoice.paid', $invoice('in_RENEWAL', 'subscription_cycle'))->assertOk();

        $renewal = Transaction::where('gateway_payment_id', 'in_RENEWAL')->sole();
        $this->assertSame($subscription->id, $renewal->subscription_id);
        $this->assertSame($pending->fresh()->user_id, $renewal->user_id);
        $this->assertNotSame($pending->fresh()->receipt_no, $renewal->receipt_no);
        $this->assertSame(1, Subscription::count());
    }

    public function test_a_subscription_set_up_outside_the_dashboard_is_adopted_for_its_donor(): void
    {
        Notification::fake();

        // Pre-2025-03-31 shape: subscription id at the top, the price embedded.
        $this->deliver('invoice.paid', [
            'object' => 'invoice', 'id' => 'in_FOREIGN', 'billing_reason' => 'subscription_cycle',
            'subscription' => 'sub_FOREIGN', 'amount_paid' => 2500, 'currency' => 'usd',
            'customer_email' => 'maya@example.com', 'customer_name' => 'Maya Iyer',
            'lines' => ['data' => [[
                'description' => '1 × Gaushala Friend (at $25.00 / month)',
                'price' => ['id' => 'price_FOREIGN', 'unit_amount' => 2500, 'currency' => 'usd',
                    'recurring' => ['interval' => 'month', 'interval_count' => 1]],
            ]]],
        ])->assertOk();

        $user = User::where('email', 'maya@example.com')->sole();
        $subscription = Subscription::where('gateway_subscription_id', 'sub_FOREIGN')->sole();

        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame($user->id, Transaction::sole()->user_id);
        $this->assertSame('price_FOREIGN', $subscription->plan->gatewayPlanId('stripe'));
        $this->assertFalse((bool) $subscription->plan->is_active, 'a mirrored plan must not be offered to donors');
        Notification::assertSentTo($user, DonorAccountCreated::class);
    }

    /** A Dashboard or API charge: no session, no invoice, the donor only reachable by id. */
    private function standaloneIntent(array $overrides = []): array
    {
        return array_merge([
            'object' => 'payment_intent',
            'id' => 'pi_STANDALONE01',
            'status' => 'succeeded',
            'amount' => 100000,
            'amount_received' => 100000,
            'currency' => 'inr',
            'invoice' => null,
            'customer' => 'cus_SHYAM',
            'latest_charge' => 'ch_STANDALONE01',
            'description' => 'Annadan seva',
            'metadata' => [],
        ], $overrides);
    }

    public function test_a_standalone_payment_is_linked_to_a_new_account_built_from_its_charge(): void
    {
        Notification::fake();
        Http::fake(['api.stripe.com/v1/charges/ch_STANDALONE01' => Http::response([
            'id' => 'ch_STANDALONE01',
            'billing_details' => ['email' => 'shyam@example.com', 'name' => 'Shyam Sundar', 'phone' => null, 'address' => null],
        ])]);

        $this->deliver('payment_intent.succeeded', $this->standaloneIntent())->assertOk();

        $user = User::where('email', 'shyam@example.com')->sole();
        $transaction = Transaction::sole();
        $this->assertSame($user->id, $transaction->user_id);
        $this->assertSame('one_off', $transaction->type);
        $this->assertSame(100000, $transaction->amount);
        $this->assertSame('Annadan seva', $transaction->purpose);
        $this->assertNotNull($transaction->receipt_no);
        Notification::assertSentTo($user, DonorAccountCreated::class);
    }

    public function test_a_standalone_payment_falls_back_to_the_customer_for_the_donor(): void
    {
        Notification::fake();
        $donor = User::factory()->create(['email' => 'shyam@example.com']);
        Http::fake([
            'api.stripe.com/v1/charges/ch_STANDALONE01' => Http::response(['id' => 'ch_STANDALONE01', 'billing_details' => []]),
            'api.stripe.com/v1/customers/cus_SHYAM' => Http::response(['id' => 'cus_SHYAM', 'email' => 'Shyam@Example.com', 'name' => 'Shyam']),
        ]);

        $this->deliver('payment_intent.succeeded', $this->standaloneIntent())->assertOk();

        $this->assertSame($donor->id, Transaction::sole()->user_id);
        Notification::assertNothingSent();
    }

    public function test_a_payment_link_is_one_payment_whichever_of_its_two_events_lands_first(): void
    {
        Notification::fake();
        Http::fake(['api.stripe.com/v1/charges/ch_PAYLINK01' => Http::response([
            'id' => 'ch_PAYLINK01', 'billing_details' => ['email' => 'gopal@example.com', 'name' => 'Gopal Das'],
        ])]);

        $this->deliver('payment_intent.succeeded', $this->standaloneIntent([
            'id' => 'pi_PAYLINK01', 'amount_received' => 250000, 'latest_charge' => 'ch_PAYLINK01', 'customer' => null,
        ]))->assertOk();
        $this->deliver('checkout.session.completed', $this->paymentLinkSession())->assertOk();

        $this->assertSame('pi_PAYLINK01', Transaction::sole()->gateway_payment_id);
        Notification::assertSentTimes(DonorAccountCreated::class, 1);
    }

    public function test_a_dashboard_one_off_is_matched_by_the_reference_on_its_payment_intent(): void
    {
        $pending = Transaction::create([
            'gateway' => 'stripe', 'type' => 'one_off', 'reference' => 'BAC-STRIPEONE01',
            'gateway_order_id' => 'cs_live_ONE01', 'amount' => 100000, 'currency' => 'INR', 'status' => 'pending',
            'donor_name' => 'Radha', 'donor_email' => 'radha@example.com', 'user_id' => User::factory()->create()->id,
        ]);

        $this->deliver('payment_intent.succeeded', $this->standaloneIntent([
            'metadata' => ['reference' => 'BAC-STRIPEONE01'],
        ]))->assertOk();

        $this->assertSame('paid', $pending->fresh()->status);
        $this->assertSame(1, Transaction::count());
    }

    public function test_an_invoices_payment_intent_is_left_to_invoice_paid(): void
    {
        $this->deliver('payment_intent.succeeded', $this->standaloneIntent(['invoice' => 'in_FIRST']))->assertOk();

        $this->assertSame(0, Transaction::count());
    }

    /** From API version 2025-03-31 the intent no longer names its invoice. */
    public function test_an_invoices_payment_intent_is_recognised_on_newer_api_versions(): void
    {
        $intent = $this->standaloneIntent();
        unset($intent['invoice']);
        Http::fake(['api.stripe.com/v1/invoice_payments*' => Http::response(['data' => [['id' => 'inpay_1', 'invoice' => 'in_FIRST']]])]);

        $this->deliver('payment_intent.succeeded', $intent)->assertOk();

        $this->assertSame(0, Transaction::count());
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'payment[payment_intent]=pi_STANDALONE01'));
    }

    public function test_a_payment_intent_whose_invoice_link_cannot_be_checked_is_retried_rather_than_guessed(): void
    {
        $intent = $this->standaloneIntent();
        unset($intent['invoice']);
        Http::fake(['api.stripe.com/v1/invoice_payments*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $this->deliver('payment_intent.succeeded', $intent)->assertServerError();

        $this->assertSame(0, Transaction::count());
    }

    public function test_invoice_paid_and_invoice_payment_succeeded_are_one_payment(): void
    {
        Notification::fake();
        $invoice = [
            'object' => 'invoice', 'id' => 'in_ONETIME', 'billing_reason' => 'manual',
            'amount_paid' => 300000, 'currency' => 'inr', 'customer_email' => 'kunj@example.com', 'customer_name' => 'Kunj',
            'lines' => ['data' => [['description' => 'Medical camp']]],
        ];

        $this->deliver('invoice.payment_succeeded', $invoice)->assertOk();
        $this->deliver('invoice.paid', $invoice)->assertOk();

        $transaction = Transaction::sole();
        $this->assertSame(User::where('email', 'kunj@example.com')->sole()->id, $transaction->user_id);
        $this->assertSame('one_off', $transaction->type);
    }

    public function test_a_subscription_checkout_charges_in_the_currency_the_donor_chose(): void
    {
        $plan = Plan::create([
            'slug' => 'monthly', 'name' => 'Monthly', 'amount' => 50000, 'currency' => 'INR', 'amount_usd' => 600,
            'interval' => 'monthly', 'interval_count' => 1, 'gateway_plan_ids' => ['stripe' => 'price_MULTI'],
        ]);
        $transaction = Transaction::create([
            'gateway' => 'stripe', 'type' => 'subscription', 'reference' => 'BAC-USDSUB01',
            'amount' => 600, 'currency' => 'USD', 'status' => 'pending',
            'donor_name' => 'Maya', 'donor_email' => 'maya@example.com',
        ]);
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_USD', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_USD'])]);

        (new StripeGateway)->startSubscription($transaction, $plan);

        Http::assertSent(fn ($request) => $request['currency'] === 'usd' && $request['line_items'][0]['price'] === 'price_MULTI');
    }

    public function test_an_invoice_with_no_money_received_is_not_receipted(): void
    {
        $this->deliver('invoice.paid', [
            'object' => 'invoice', 'id' => 'in_TRIAL', 'billing_reason' => 'subscription_create',
            'subscription' => 'sub_TRIAL', 'amount_paid' => 0, 'currency' => 'inr',
            'customer_email' => 'trial@example.com',
        ])->assertOk();

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, User::count());
    }
}
