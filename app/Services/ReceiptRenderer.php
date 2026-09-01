<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Renders a receipt to PDF with Gotenberg — the same service the n8n workflows
 * use, so the emailed receipt and the downloaded one come out of one renderer.
 */
class ReceiptRenderer
{
    public function html(Transaction $transaction): string
    {
        return view('receipts.pdf', [
            'txn' => $transaction,
            'org' => config('payments.org'),
        ])->render();
    }

    public function pdf(Transaction $transaction): string
    {
        $base = rtrim((string) config('payments.gotenberg.url'), '/');

        try {
            $response = Http::timeout(30)
                // Gotenberg requires the primary document to be named exactly this.
                ->attach(
                    'files',
                    $this->html($transaction),
                    'index.html'
                )
                ->post($base.'/forms/chromium/convert/html', [
                    // Defaults to false, which would drop every background colour
                    // and print the amount panel white.
                    'printBackground' => 'true',
                    'paperWidth' => '8.27',    // A4
                    'paperHeight' => '11.69',
                    'marginTop' => '0.55',
                    'marginBottom' => '0.55',
                    'marginLeft' => '0.5',
                    'marginRight' => '0.5',
                ]);
        } catch (ConnectionException $e) {
            // Http::post throws before any response exists, so the check below
            // would never run — say what is actually wrong instead of leaking
            // a raw cURL error to the donor.
            throw new RuntimeException(
                "Could not reach Gotenberg at {$base} to render receipt ".
                "{$transaction->receipt_no}. Start it (docker compose up -d gotenberg) ".
                'or set GOTENBERG_URL.',
                previous: $e
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Gotenberg returned {$response->status()} rendering receipt ".
                "{$transaction->receipt_no}. Is it running and reachable at {$base}?"
            );
        }

        return $response->body();
    }

    public function filename(Transaction $transaction): string
    {
        $name = $transaction->receipt_no ?: $transaction->reference;

        return 'Receipt-'.preg_replace('/[^A-Za-z0-9\-]/', '-', $name).'.pdf';
    }
}
