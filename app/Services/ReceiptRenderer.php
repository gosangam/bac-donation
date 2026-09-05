<?php

namespace App\Services;

use App\Models\Transaction;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Renders a receipt to PDF in-process with Dompdf.
 *
 * Deliberately no external renderer: a receipt is issued the moment a payment
 * clears, inside a queued job, and making that depend on a separate container
 * being up meant a donor's receipt failed for reasons that had nothing to do
 * with their donation. Dompdf is pure PHP, so wherever Laravel runs, receipts
 * render — including shared hosting, where no daemon can be started.
 *
 * The template is table-based with inline styles, which is what Dompdf handles
 * well; it is also what email clients need, so both receipts stay consistent.
 */
class ReceiptRenderer
{
    /** How long a fetched logo stays cached. It changes approximately never. */
    private const LOGO_TTL_DAYS = 30;

    /**
     * @param  bool  $embedLogo  True for PDF, where the image must travel inside
     *                           the document. HTML keeps the remote URL, since
     *                           several mail clients refuse to show data URIs.
     */
    public function html(Transaction $transaction, bool $embedLogo = false): string
    {
        $org = config('payments.org');

        if ($embedLogo) {
            $org['logo_url'] = $this->logoDataUri() ?? null;
        }

        return view('receipts.pdf', [
            'txn' => $transaction,
            'org' => $org,
        ])->render();
    }

    public function pdf(Transaction $transaction): string
    {
        $options = new Options;
        // No network access from the renderer. Everything it needs is already
        // in the document, so a remote fetch could only be an SSRF foothold or
        // a hang on a slow host while a queue worker waits.
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        // DejaVu Sans is the bundled font that has the rupee sign; the PDF
        // core fonts do not, and render it as "?".
        $options->set('defaultFont', 'DejaVu Sans');
        // Bundled fonts get compiled into a cache on first use; without a
        // writable directory Dompdf falls back and logs on every render.
        $options->set('fontDir', storage_path('fonts'));
        $options->set('fontCache', storage_path('fonts'));

        if (! is_dir(storage_path('fonts'))) {
            mkdir(storage_path('fonts'), 0755, true);
        }

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->html($transaction, embedLogo: true), 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * The logo as a data URI, or null if it cannot be had.
     *
     * A missing logo must never stop a receipt: the template already falls back
     * to the organisation name in brand colour, which is a perfectly valid
     * receipt. This is the failure that produced the broken-image icon before.
     */
    public function logoDataUri(): ?string
    {
        $url = config('payments.org.logo_url');

        if (blank($url)) {
            return null;
        }

        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        return Cache::remember(
            'receipt.logo.'.md5($url),
            now()->addDays(self::LOGO_TTL_DAYS),
            function () use ($url) {
                try {
                    // A local path in public/ is read straight off disk — no
                    // HTTP round trip, and it works with no outbound network.
                    if (! str_starts_with($url, 'http')) {
                        $path = public_path(ltrim(parse_url($url, PHP_URL_PATH) ?? $url, '/'));

                        return is_readable($path)
                            ? $this->encode(file_get_contents($path), mime_content_type($path))
                            : null;
                    }

                    $response = Http::timeout(10)->get($url);

                    if (! $response->successful()) {
                        Log::warning('Receipt logo fetch failed', [
                            'url' => $url, 'status' => $response->status(),
                        ]);

                        return null;
                    }

                    return $this->encode(
                        $response->body(),
                        $response->header('Content-Type') ?: 'image/jpeg'
                    );
                } catch (\Throwable $e) {
                    Log::warning('Receipt logo fetch errored', [
                        'url' => $url, 'error' => $e->getMessage(),
                    ]);

                    return null;
                }
            }
        );
    }

    private function encode(string $bytes, string $mime): string
    {
        // Content-Type can arrive as "image/jpeg; charset=binary".
        $mime = trim(explode(';', $mime)[0]);

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    public function filename(Transaction $transaction): string
    {
        $name = $transaction->receipt_no ?: $transaction->reference;

        return 'Receipt-'.preg_replace('/[^A-Za-z0-9\-]/', '-', $name).'.pdf';
    }
}
