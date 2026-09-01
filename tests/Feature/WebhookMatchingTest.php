<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Payments\WebhookEvent;
use App\Services\PaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
    }

    private function pending(array $overrides = []): Transaction
    {
        $user = User::create(['name' => 'Radha', 'email' => 'radha@x.test', 'password' => 'secret123']);

        return Transaction::create(array_merge([
            'user_id' => $user->id, 'gateway' => 'razorpay', 'type' => 'one_off',
            'reference' => 'BAC-KNOWNREF0001',
            'gateway_order_id' => 'order_LIVE123',
            'amount' => 75000, 'currency' => 'INR', 'status' => 'pending',
            'donor_name' => 'Radha', 'donor_email' => 'radha@x.test',
        ], $overrides));
    }

    /**
     * The production failure: a real captured payment arrived with
     * notes.reference = null, so it matched nothing and was discarded while the
     * donor's transaction sat pending.
     */
    public function test_a_payment_with_no_reference_is_matched_by_its_order_id(): void
    {
        $txn = $this->pending();

        app(PaymentRecorder::class)->recordSuccess('razorpay', new WebhookEvent(
            type: 'payment_succeeded',
            reference: null,                       // exactly what production sent
            paymentId: 'pay_TWmDGUvRWChWEV',
            orderId: 'order_LIVE123',
            amount: 75000,
            currency: 'INR',
            method: 'UPI',
        ));

        $txn->refresh();
        $this->assertSame('paid', $txn->status);
        $this->assertSame('pay_TWmDGUvRWChWEV', $txn->gateway_payment_id);
        $this->assertNotNull($txn->receipt_no);
        $this->assertSame(1, Transaction::count(), 'must update the row, not create a second');
    }

    public function test_the_reference_still_wins_when_present(): void
    {
        $txn = $this->pending(['gateway_order_id' => null]);

        app(PaymentRecorder::class)->recordSuccess('razorpay', new WebhookEvent(
            type: 'payment_succeeded',
            reference: 'BAC-KNOWNREF0001',
            paymentId: 'pay_BYREF01',
            amount: 75000,
            currency: 'INR',
        ));

        $this->assertSame('paid', $txn->fresh()->status);
    }

    public function test_an_order_id_belonging_to_another_gateway_is_not_matched(): void
    {
        $this->pending(['gateway' => 'stripe']);

        $result = app(PaymentRecorder::class)->recordSuccess('razorpay', new WebhookEvent(
            type: 'payment_succeeded',
            paymentId: 'pay_X',
            orderId: 'order_LIVE123',
        ));

        $this->assertNull($result, 'order ids must not collide across gateways');
    }

    public function test_a_replay_matched_by_order_id_does_not_double_receipt(): void
    {
        $txn = $this->pending();
        $event = new WebhookEvent(
            type: 'payment_succeeded',
            paymentId: 'pay_REPLAY01',
            orderId: 'order_LIVE123',
            amount: 75000,
            currency: 'INR',
        );

        $recorder = app(PaymentRecorder::class);
        $recorder->recordSuccess('razorpay', $event);
        $first = $txn->fresh()->receipt_no;
        $recorder->recordSuccess('razorpay', $event);

        $this->assertSame($first, $txn->fresh()->receipt_no);
        $this->assertSame(1, Transaction::count());
    }

    public function test_checkout_options_now_carry_the_reference_to_the_payment(): void
    {
        $txn = $this->pending();

        // The notes handed to Checkout become the payment's notes; the order's
        // notes never reach the payment entity.
        $options = (new \ReflectionClass(\App\Payments\Gateways\RazorpayGateway::class))
            ->getMethod('checkoutOptions');
        $options->setAccessible(true);

        $result = $options->invoke(new \App\Payments\Gateways\RazorpayGateway, $txn, []);

        $this->assertSame('BAC-KNOWNREF0001', $result['notes']['reference']);
    }
}
