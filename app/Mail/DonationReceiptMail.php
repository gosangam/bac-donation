<?php

namespace App\Mail;

use App\Models\Transaction;
use App\Services\ReceiptRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DonationReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Transaction $transaction,
        public string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        $org = config('payments.org');

        return new Envelope(
            from: new Address($org['email'], $org['name']),
            replyTo: [new Address($org['email'], $org['name'])],
            to: [new Address($this->transaction->donor_email, $this->transaction->donor_name)],
            bcc: config('payments.receipts.bcc'),
            subject: "Receipt {$this->transaction->receipt_no} — thank you for your donation of "
                .$this->transaction->amount_formatted,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.receipt',
            text: 'mail.receipt-text',
            with: [
                'txn' => $this->transaction,
                'org' => config('payments.org'),
            ],
        );
    }

    public function attachments(): array
    {
        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $this->pdf,
                app(ReceiptRenderer::class)->filename($this->transaction)
            )->withMime('application/pdf'),
        ];
    }
}
