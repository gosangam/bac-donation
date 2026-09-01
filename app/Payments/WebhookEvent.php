<?php

namespace App\Payments;

/** A gateway webhook, normalised to the few things this app acts on. */
class WebhookEvent
{
    public function __construct(
        public readonly string $type,              // payment_succeeded | payment_failed |
                                                   // subscription_activated | subscription_cancelled | ignored
        public readonly ?string $reference = null, // our own transaction reference, when the gateway echoed it
        public readonly ?string $paymentId = null,
        public readonly ?string $orderId = null,
        public readonly ?string $subscriptionId = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $method = null,
        public readonly ?string $status = null,
        public readonly array $raw = [],
        public readonly string $reason = '',       // why an event was ignored
    ) {}

    public static function ignored(string $reason, array $raw = []): self
    {
        return new self(type: 'ignored', raw: $raw, reason: $reason);
    }
}
