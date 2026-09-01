<?php

namespace Tests\Feature;

use App\Jobs\SendDonationReceipt;
use App\Mail\DonationReceiptMail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReceiptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ReceiptEmailTest extends TestCase
{
    use RefreshDatabase;

    private function paidTransaction(array $overrides = []): Transaction
    {
        $user = User::create([
            'name' => 'Radha Sharma', 'email' => 'radha@example.com',
            'password' => 'secret123', 'phone' => '+91 98765 43210',
        ]);

        return Transaction::create(array_merge([
            'user_id' => $user->id, 'gateway' => 'razorpay', 'type' => 'one_off',
            'reference' => Transaction::newReference(), 'amount' => 50000, 'currency' => 'INR',
            'status' => 'paid', 'paid_at' => now(), 'receipt_no' => 'BAC/2026-27/00001',
            'purpose' => 'Cow Feed Fund', 'method' => 'Visa credit ••••4366',
            'donor_name' => 'Radha Sharma', 'donor_email' => 'radha@example.com',
        ], $overrides));
    }

    /** Stub Gotenberg: these tests are about the email, not the renderer. */
    private function fakeRenderer(): void
    {
        $renderer = Mockery::mock(ReceiptRenderer::class);
        $renderer->shouldReceive('pdf')->andReturn('%PDF-1.4 fake');
        $renderer->shouldReceive('filename')->andReturn('Receipt-BAC-2026-27-00001.pdf');
        $this->app->instance(ReceiptRenderer::class, $renderer);
    }

    public function test_it_emails_the_receipt_with_a_pdf_attached(): void
    {
        Mail::fake();
        $this->fakeRenderer();
        $txn = $this->paidTransaction();

        (new SendDonationReceipt($txn))->handle(app(ReceiptRenderer::class));

        Mail::assertSent(DonationReceiptMail::class, function (DonationReceiptMail $mail) use ($txn) {
            $envelope = $mail->envelope();

            return $mail->hasTo($txn->donor_email)
                && str_contains($envelope->subject, 'BAC/2026-27/00001')
                && str_contains($envelope->subject, '₹500.00')
                && $mail->attachments() !== [];
        });

        $this->assertNotNull($txn->fresh()->receipt_emailed_at);
    }

    public function test_it_does_not_email_the_same_receipt_twice(): void
    {
        Mail::fake();
        $this->fakeRenderer();
        $txn = $this->paidTransaction();

        // Two deliveries of the same webhook, as a gateway retry would produce.
        (new SendDonationReceipt($txn))->handle(app(ReceiptRenderer::class));
        (new SendDonationReceipt($txn))->handle(app(ReceiptRenderer::class));

        Mail::assertSentCount(1);
    }

    public function test_it_never_emails_a_receipt_for_an_unpaid_payment(): void
    {
        Mail::fake();
        $this->fakeRenderer();
        $txn = $this->paidTransaction(['status' => 'pending', 'paid_at' => null, 'receipt_no' => null]);

        (new SendDonationReceipt($txn))->handle(app(ReceiptRenderer::class));

        Mail::assertNothingSent();
    }

    public function test_it_skips_when_there_is_no_donor_email(): void
    {
        Mail::fake();
        $this->fakeRenderer();
        $txn = $this->paidTransaction(['donor_email' => '']);

        (new SendDonationReceipt($txn))->handle(app(ReceiptRenderer::class));

        Mail::assertNothingSent();
        $this->assertNull($txn->fresh()->receipt_emailed_at);
    }

    public function test_the_toggle_stops_the_app_emailing_when_n8n_owns_it(): void
    {
        Queue::fake();
        config(['payments.receipts.email' => false]);

        $recorder = app(\App\Services\PaymentRecorder::class);
        $txn = $this->paidTransaction(['status' => 'pending', 'paid_at' => null, 'receipt_no' => null]);

        $recorder->recordSuccess('razorpay', new \App\Payments\WebhookEvent(
            type: 'payment_succeeded',
            reference: $txn->reference,
            paymentId: 'pay_TOGGLE01',
            amount: 50000,
            currency: 'INR',
        ));

        Queue::assertNotPushed(SendDonationReceipt::class);
        $this->assertSame('paid', $txn->fresh()->status);
    }

    public function test_recording_a_payment_queues_the_receipt_email(): void
    {
        Queue::fake();
        config(['payments.receipts.email' => true]);

        $txn = $this->paidTransaction(['status' => 'pending', 'paid_at' => null, 'receipt_no' => null]);

        app(\App\Services\PaymentRecorder::class)->recordSuccess('razorpay', new \App\Payments\WebhookEvent(
            type: 'payment_succeeded',
            reference: $txn->reference,
            paymentId: 'pay_QUEUE01',
            amount: 50000,
            currency: 'INR',
        ));

        Queue::assertPushed(SendDonationReceipt::class);
    }
}
