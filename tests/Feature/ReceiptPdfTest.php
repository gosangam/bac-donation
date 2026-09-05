<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\ReceiptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    private function paidTransaction(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => null,
            'gateway' => 'razorpay', 'type' => 'one_off',
            'reference' => Transaction::newReference(),
            'receipt_no' => 'BAC/2026-27/00002',
            'amount' => 75000, 'currency' => 'INR', 'status' => 'paid',
            'paid_at' => now(),
            'purpose' => 'Cow Feed Fund',
            'donor_name' => 'Radha Sharma', 'donor_email' => 'radha@example.com',
            'donor_phone' => '+91 98765 43210',
            'donor_address' => '21 Parikrama Marg, Vrindavan',
        ], $overrides));
    }

    /** The whole point: no Gotenberg, no n8n, no container to start. */
    public function test_a_pdf_renders_in_process(): void
    {
        Http::fake();   // any outbound call would be a failure of the design

        $pdf = app(ReceiptRenderer::class)->pdf($this->paidTransaction());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
    }

    /** A logo that cannot be fetched must not cost the donor their receipt. */
    public function test_an_unreachable_logo_does_not_break_the_receipt(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('', 500)]);

        $renderer = app(ReceiptRenderer::class);

        $this->assertNull($renderer->logoDataUri());
        $this->assertStringStartsWith('%PDF-', $renderer->pdf($this->paidTransaction()));
    }

    public function test_a_fetched_logo_is_embedded_as_a_data_uri(): void
    {
        Cache::flush();
        // A one-pixel PNG is enough: this asserts the encoding, not the image.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake(['*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $uri = app(ReceiptRenderer::class)->logoDataUri();

        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertStringContainsString(base64_encode($png), $uri);
    }

    /** Content-Type often arrives as "image/jpeg; charset=binary". */
    public function test_a_charset_suffixed_content_type_is_trimmed(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg; charset=binary'])]);

        $this->assertStringStartsWith(
            'data:image/jpeg;base64,',
            app(ReceiptRenderer::class)->logoDataUri()
        );
    }

    /**
     * The PDF core fonts have no U+20B9 and print it as "?", which would make
     * the amount on every Indian receipt unreadable.
     */
    public function test_the_template_uses_a_font_that_has_the_rupee_sign(): void
    {
        $html = app(ReceiptRenderer::class)->html($this->paidTransaction());

        $this->assertStringContainsString('₹750.00', $html);
        $this->assertStringContainsString("'DejaVu Sans'", $html);
        $this->assertStringNotContainsString('font:14px/1.5 Helvetica', $html);
    }

    public function test_a_dollar_receipt_renders(): void
    {
        Http::fake();

        $txn = $this->paidTransaction(['amount' => 1200, 'currency' => 'USD', 'gateway' => 'paypal']);

        $this->assertStringContainsString('$12.00', app(ReceiptRenderer::class)->html($txn));
        $this->assertStringStartsWith('%PDF-', app(ReceiptRenderer::class)->pdf($txn));
    }

    public function test_the_filename_is_built_from_the_receipt_number(): void
    {
        $this->assertSame(
            'Receipt-BAC-2026-27-00002.pdf',
            app(ReceiptRenderer::class)->filename($this->paidTransaction())
        );
    }
}
