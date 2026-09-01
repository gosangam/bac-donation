<?php

namespace App\Jobs;

use App\Mail\DonationReceiptMail;
use App\Models\Transaction;
use App\Services\ReceiptRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Queued deliberately: rendering the PDF calls Gotenberg and sending calls SMTP,
 * and neither belongs inside a webhook request. A gateway that does not get a
 * fast 2xx retries the whole delivery.
 */
class SendDonationReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public Transaction $transaction) {}

    public function handle(ReceiptRenderer $renderer): void
    {
        $transaction = $this->transaction->fresh();

        if (! $transaction || ! $transaction->isPaid()) {
            return;
        }

        // Guards against a webhook retry, a manual resend racing the automatic
        // send, and this job itself being retried after the mail went out.
        if ($transaction->receipt_emailed_at) {
            return;
        }

        if (blank($transaction->donor_email)) {
            Log::warning('No donor email on transaction; receipt not sent', [
                'transaction' => $transaction->id,
            ]);

            return;
        }

        try {
            $pdf = $renderer->pdf($transaction);

            Mail::send(new DonationReceiptMail($transaction, $pdf));
        } catch (\Throwable $e) {
            // Recorded on every attempt, not just the final one: with retries and
            // backoff the last attempt can be ~12 minutes away, and until then an
            // admin would see nothing but "Queued".
            $transaction->forceFill([
                'receipt_email_error' => Str::limit($e->getMessage(), 250),
            ])->save();

            throw $e;   // let the queue retry as configured
        }

        $transaction->forceFill([
            'receipt_emailed_at' => now(),
            'receipt_email_error' => null,
        ])->save();
    }

    public function failed(\Throwable $e): void
    {
        // Recorded on the row so an admin can see why, and resend once fixed.
        $this->transaction->forceFill([
            'receipt_email_error' => Str::limit($e->getMessage(), 250),
        ])->save();

        Log::error('Receipt email failed', [
            'transaction' => $this->transaction->id,
            'error' => $e->getMessage(),
        ]);
    }
}
