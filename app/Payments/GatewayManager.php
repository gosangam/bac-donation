<?php

namespace App\Payments;

use App\Payments\Gateways\PayPalGateway;
use App\Payments\Gateways\RazorpayGateway;
use App\Payments\Gateways\StripeGateway;
use InvalidArgumentException;

class GatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct()
    {
        $this->gateways = [
            'razorpay' => new RazorpayGateway,
            'stripe' => new StripeGateway,
            'paypal' => new PayPalGateway,
        ];
    }

    public function get(string $key): PaymentGateway
    {
        if (! isset($this->gateways[$key])) {
            throw new InvalidArgumentException("Unknown payment gateway: {$key}");
        }

        return $this->gateways[$key];
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }

    /** Only gateways with keys configured — the rest must not be offered. */
    public function available(?string $currency = null): array
    {
        return array_filter($this->gateways, function (PaymentGateway $gateway) use ($currency) {
            if (! $gateway->isConfigured()) {
                return false;
            }

            return $currency === null
                || in_array(strtoupper($currency), $gateway->supportedCurrencies(), true);
        });
    }
}
