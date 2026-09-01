<?php

namespace App\Payments;

/**
 * What a gateway needs from the app to start a checkout, and what it hands back.
 *
 * Two shapes come back because the gateways genuinely differ: Stripe and PayPal
 * redirect the browser away, Razorpay opens a modal over our own page. Pretending
 * otherwise would mean fighting one of them.
 */
class CheckoutIntent
{
    private function __construct(
        public readonly string $mode,        // 'redirect' | 'inline'
        public readonly ?string $redirectUrl = null,
        public readonly array $inline = [],  // options handed to the gateway's JS
    ) {}

    public static function redirect(string $url): self
    {
        return new self('redirect', $url);
    }

    public static function inline(array $options): self
    {
        return new self('inline', null, $options);
    }
}
