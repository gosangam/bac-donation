<?php

namespace App\Services;

use App\Models\Transaction;
use App\Payments\GatewayManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Asks the gateway what it thinks of a transaction, and records the answer.
 *
 * Webhooks are still the primary path — this exists because they can be missed,
 * delayed, or blocked by a misconfigured endpoint, and a donor who has paid
 * should not be looking at "pending" forever just because a POST went astray.
 */
class TransactionReconciler
{
    public function __construct(
        private GatewayManager $gateways,
        private PaymentRecorder $recorder,
    ) {}

    /** Seconds to wait before asking the same gateway about the same row again. */
    private const THROTTLE_SECONDS = 15;

    /** Stop polling a transaction that was never going to settle. */
    private const GIVE_UP_AFTER_HOURS = 72;

    public function reconcile(Transaction $transaction): Transaction
    {
        if (! $this->shouldSync($transaction)) {
            return $transaction;
        }

        $gateway = $this->gateways->get($transaction->gateway);

        if (! $gateway->isConfigured()) {
            return $transaction;
        }

        try {
            $event = $gateway->fetchStatus($transaction);

            $transaction->forceFill([
                'gateway_synced_at' => now(),
                'gateway_sync_error' => null,
            ])->save();

            if (! $event) {
                return $transaction->fresh();   // gateway agrees it is not settled
            }

            return $this->recorder->applyTo($transaction, $transaction->gateway, $event);
        } catch (\Throwable $e) {
            report($e);

            // A gateway being down must not break the page — the donor still
            // needs to see what we know, and the note tells them why it is stale.
            $transaction->forceFill([
                'gateway_synced_at' => now(),
                'gateway_sync_error' => Str::limit($e->getMessage(), 200),
            ])->save();

            Log::warning('Transaction status poll failed', [
                'transaction' => $transaction->id,
                'gateway' => $transaction->gateway,
                'error' => $e->getMessage(),
            ]);

            return $transaction->fresh();
        }
    }

    private function shouldSync(Transaction $transaction): bool
    {
        // A settled payment cannot change into something else, so there is
        // nothing to ask about — and asking would spend an API call per pageview.
        if ($transaction->status !== 'pending') {
            return false;
        }

        // Nothing was ever handed to the gateway.
        if (blank($transaction->gateway_order_id) && blank($transaction->gateway_payment_id)) {
            return false;
        }

        if ($transaction->created_at->lt(now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
            return false;
        }

        // Throttled so holding refresh does not become a burst of API calls.
        return $transaction->gateway_synced_at === null
            || $transaction->gateway_synced_at->lt(now()->subSeconds(self::THROTTLE_SECONDS));
    }
}
