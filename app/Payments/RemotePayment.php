<?php

namespace App\Payments;

/**
 * What a gateway knows about a payment this app has no row for.
 *
 * Subscriptions that predate the dashboard — or were set up directly in a
 * gateway's own console — keep charging, and their webhooks arrive referencing
 * nothing we hold. The gateway is then the only source of the donor's identity
 * and of what they are paying for, so it is asked, and the answer is normalised
 * here before any of it reaches the database.
 *
 * Every field is optional: a gateway that cannot answer part of this is still
 * more useful than one that answers none of it.
 */
class RemotePayment
{
    public function __construct(
        public readonly ?string $donorName = null,
        public readonly ?string $donorEmail = null,
        public readonly ?string $donorPhone = null,
        public readonly ?string $donorAddress = null,

        /** The gateway's real subscription id; null for a one-off payment. */
        public readonly ?string $subscriptionId = null,

        /** The plan as the gateway holds it, for matching or materialising one. */
        public readonly ?string $planId = null,
        public readonly ?string $planName = null,
        public readonly ?int $planAmount = null,        // minor units
        public readonly ?string $planCurrency = null,
        public readonly ?string $planInterval = null,   // daily|weekly|monthly|yearly
        public readonly ?int $planIntervalCount = null,

        public readonly ?string $purpose = null,
    ) {}

    public function isSubscription(): bool
    {
        return filled($this->subscriptionId);
    }

    /** Whether there is enough here to raise an account for the donor. */
    public function identifiesDonor(): bool
    {
        return filled($this->donorEmail);
    }
}
