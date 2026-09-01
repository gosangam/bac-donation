<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Payments\WebhookEvent;
use App\Services\TransactionReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransactionSyncTest extends TestCase
{
    use RefreshDatabase;

    private function pending(array $overrides = []): Transaction
    {
        $user = User::create(['name' => 'Radha', 'email' => 'radha@x.test', 'password' => 'secret123']);

        return Transaction::create(array_merge([
            'user_id' => $user->id, 'gateway' => 'razorpay', 'type' => 'one_off',
            'reference' => Transaction::newReference(),
            'gateway_order_id' => 'order_ABC123',
            'amount' => 50000, 'currency' => 'INR', 'status' => 'pending',
            'donor_name' => 'Radha', 'donor_email' => 'radha@x.test',
        ], $overrides));
    }

    private function razorpayReturns(array $payments): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders/*/payments' => Http::response(['items' => $payments]),
        ]);
    }

    public function test_a_captured_payment_found_by_polling_is_recorded_with_a_receipt(): void
    {
        Queue::fake();
        Notification::fake();
        $txn = $this->pending();

        $this->razorpayReturns([[
            'id' => 'pay_POLLED01', 'status' => 'captured', 'amount' => 50000,
            'currency' => 'INR', 'order_id' => 'order_ABC123', 'method' => 'upi',
            'vpa' => 'radha@okaxis', 'notes' => ['reference' => $txn->reference],
        ]]);

        app(TransactionReconciler::class)->reconcile($txn);

        $txn->refresh();
        $this->assertSame('paid', $txn->status);
        $this->assertSame('pay_POLLED01', $txn->gateway_payment_id);
        $this->assertNotNull($txn->receipt_no);
        $this->assertNotNull($txn->paid_at);
        $this->assertSame('UPI (radha@okaxis)', $txn->method);
    }

    public function test_an_authorised_but_uncaptured_payment_is_not_treated_as_received(): void
    {
        $txn = $this->pending();

        // Money is held, not taken. Receipting it would document a payment that
        // can still fall through.
        $this->razorpayReturns([[
            'id' => 'pay_AUTH01', 'status' => 'authorized', 'amount' => 50000, 'currency' => 'INR',
        ]]);

        app(TransactionReconciler::class)->reconcile($txn);

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertNull($txn->fresh()->receipt_no);
    }

    public function test_a_failed_payment_is_marked_failed(): void
    {
        $txn = $this->pending();
        $this->razorpayReturns([['id' => 'pay_FAIL01', 'status' => 'failed']]);

        app(TransactionReconciler::class)->reconcile($txn);

        $this->assertSame('failed', $txn->fresh()->status);
    }

    public function test_it_does_not_call_the_gateway_for_a_settled_transaction(): void
    {
        Http::fake();
        $txn = $this->pending(['status' => 'paid', 'paid_at' => now(), 'receipt_no' => 'BAC/2026-27/00001']);

        app(TransactionReconciler::class)->reconcile($txn);

        Http::assertNothingSent();
    }

    public function test_polling_is_throttled(): void
    {
        $txn = $this->pending(['gateway_synced_at' => now()->subSeconds(3)]);
        Http::fake();

        app(TransactionReconciler::class)->reconcile($txn);

        Http::assertNothingSent();
    }

    public function test_it_polls_again_once_the_throttle_window_passes(): void
    {
        $txn = $this->pending(['gateway_synced_at' => now()->subMinute()]);
        $this->razorpayReturns([]);

        app(TransactionReconciler::class)->reconcile($txn);

        Http::assertSentCount(1);
    }

    public function test_it_gives_up_on_a_very_old_pending_transaction(): void
    {
        Http::fake();
        $txn = $this->pending();
        $txn->forceFill(['created_at' => now()->subDays(5)])->save();

        app(TransactionReconciler::class)->reconcile($txn);

        Http::assertNothingSent();
    }

    public function test_a_gateway_outage_does_not_break_the_page(): void
    {
        $txn = $this->pending();
        Http::fake(['api.razorpay.com/*' => Http::response('gateway down', 500)]);

        $result = app(TransactionReconciler::class)->reconcile($txn);

        $this->assertSame('pending', $result->status);
        $this->assertNotNull($result->gateway_synced_at);

        // The page itself must still render.
        $this->actingAs($txn->user)->get(route('transactions.show', $txn))->assertOk();
    }

    public function test_polling_cannot_issue_a_second_receipt(): void
    {
        Queue::fake();
        Notification::fake();
        $txn = $this->pending();
        $this->razorpayReturns([[
            'id' => 'pay_ONCE01', 'status' => 'captured', 'amount' => 50000, 'currency' => 'INR',
        ]]);

        $reconciler = app(TransactionReconciler::class);
        $reconciler->reconcile($txn);
        $first = $txn->fresh()->receipt_no;

        // Force a second poll past the throttle; the row is already paid.
        $txn->fresh()->forceFill(['gateway_synced_at' => now()->subMinute()])->save();
        $reconciler->reconcile($txn->fresh());

        $this->assertSame($first, $txn->fresh()->receipt_no);
        $this->assertSame(1, Transaction::count());
    }

    public function test_a_webhook_arriving_after_polling_does_not_duplicate_anything(): void
    {
        Queue::fake();
        Notification::fake();
        $txn = $this->pending();
        $this->razorpayReturns([[
            'id' => 'pay_BOTH01', 'status' => 'captured', 'amount' => 50000, 'currency' => 'INR',
            'notes' => ['reference' => $txn->reference],
        ]]);

        app(TransactionReconciler::class)->reconcile($txn);

        // Same payment now arrives by webhook, as it normally would.
        app(\App\Services\PaymentRecorder::class)->recordSuccess('razorpay', new WebhookEvent(
            type: 'payment_succeeded',
            reference: $txn->reference,
            paymentId: 'pay_BOTH01',
            amount: 50000,
            currency: 'INR',
        ));

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, Transaction::whereNotNull('receipt_no')->count());
    }
}
