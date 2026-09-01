<?php

namespace App\Payments;

use App\Models\Plan;
use App\Models\Transaction;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function key(): string;

    public function displayName(): string;

    /** False when the gateway has no keys configured, so the UI can hide it. */
    public function isConfigured(): bool;

    /** Currencies this gateway is set up to take here. */
    public function supportedCurrencies(): array;

    /**
     * Recurring plans that already exist inside the gateway, so an admin can pick
     * one instead of copying an id by hand.
     *
     * @return array<int, array{id: string, name: string, amount: ?int, currency: ?string, cadence: ?string}>
     */
    public function listPlans(): array;

    /** Start a one-off payment for an already-created pending transaction. */
    public function startOneOff(Transaction $transaction): CheckoutIntent;

    /** Start a recurring subscription. The first charge creates a Transaction via webhook. */
    public function startSubscription(Transaction $transaction, Plan $plan): CheckoutIntent;

    /**
     * Ask the gateway what it currently thinks of this transaction.
     *
     * Returns null when there is nothing conclusive to report — still pending, or
     * nothing to look it up by. A returned event is fed to PaymentRecorder, the
     * same path webhooks take, so a payment discovered this way is recorded once
     * and gets its receipt exactly like any other.
     */
    public function fetchStatus(Transaction $transaction): ?WebhookEvent;

    /**
     * Verify authenticity. Implementations must fail closed: anything they cannot
     * positively verify is rejected, because this endpoint marks money received.
     */
    public function verifyWebhook(Request $request): bool;

    public function parseWebhook(Request $request): WebhookEvent;

    /** Confirm a payment on the browser-return leg, for gateways that need it. */
    public function confirmReturn(Transaction $transaction, Request $request): bool;
}
