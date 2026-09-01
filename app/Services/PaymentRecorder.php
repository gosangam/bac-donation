<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Transaction;
use App\Jobs\LinkOrCreateDonorAccount;
use App\Jobs\SendDonationReceipt;
use App\Payments\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place a payment is marked received.
 *
 * Both the webhook and the browser-return leg call this, and gateways retry
 * webhooks, so it must be safe to call repeatedly for the same payment. It is
 * keyed on the gateway's payment id: that is stable across retries and across
 * the two different events some gateways send for one charge.
 */
class PaymentRecorder
{
    public function recordSuccess(string $gateway, WebhookEvent $event): ?Transaction
    {
        return DB::transaction(function () use ($gateway, $event) {
            $transaction = $this->locate($gateway, $event);

            if (! $transaction) {
                // A renewal has no pending row of ours — the donor is not at a
                // checkout, the gateway simply charged the saved mandate.
                $transaction = $this->createRenewal($gateway, $event);
            }

            if (! $transaction) {
                Log::warning('Payment could not be matched to a transaction', [
                    'gateway' => $gateway,
                    'payment_id' => $event->paymentId,
                    'reference' => $event->reference,
                ]);

                return null;
            }

            if ($transaction->isPaid()) {
                return $transaction;   // already recorded; nothing to do
            }

            $transaction->fill(array_filter([
                'gateway_payment_id' => $event->paymentId,
                'gateway_order_id' => $event->orderId ?: $transaction->gateway_order_id,
                'method' => $event->method,
                'amount' => $event->amount ?: $transaction->amount,
                'currency' => $event->currency ?: $transaction->currency,
                'gateway_payload' => $event->raw,
            ]));

            $transaction->status = 'paid';
            $transaction->paid_at = now();
            $transaction->save();

            // Only now, once money is confirmed received.
            $transaction->assignReceiptNumber();

            $this->activateSubscription($transaction, $gateway, $event);

            $this->linkDonorAccount($transaction);
            $this->emailReceipt($transaction);

            return $transaction->fresh();
        });
    }

    /**
     * Apply an event to a transaction we already hold.
     *
     * Used by status polling, where the row is known and the gateway's response
     * may carry no reference of ours to look it up by. Going through
     * recordSuccess() there would risk matching nothing and creating a duplicate.
     */
    public function applyTo(Transaction $transaction, string $gateway, WebhookEvent $event): Transaction
    {
        if ($event->type === 'payment_failed') {
            return $this->recordFailureFor($transaction, $event);
        }

        return DB::transaction(function () use ($transaction, $gateway, $event) {
            if ($transaction->isPaid()) {
                return $transaction;   // already recorded; polling changes nothing
            }

            $transaction->fill(array_filter([
                'gateway_payment_id' => $event->paymentId,
                'gateway_order_id' => $event->orderId ?: $transaction->gateway_order_id,
                'method' => $event->method,
                'amount' => $event->amount ?: $transaction->amount,
                'currency' => $event->currency ?: $transaction->currency,
                'gateway_payload' => $event->raw,
            ]));

            $transaction->status = 'paid';
            $transaction->paid_at = now();
            $transaction->save();

            $transaction->assignReceiptNumber();

            $this->activateSubscription($transaction, $gateway, $event);
            $this->linkDonorAccount($transaction);
            $this->emailReceipt($transaction);

            return $transaction->fresh();
        });
    }

    private function recordFailureFor(Transaction $transaction, WebhookEvent $event): Transaction
    {
        if ($transaction->isPaid()) {
            return $transaction;   // never walk back a confirmed payment
        }

        $transaction->update([
            'status' => 'failed',
            'gateway_payload' => $event->raw,
        ]);

        return $transaction;
    }

    public function recordFailure(string $gateway, WebhookEvent $event): ?Transaction
    {
        $transaction = $this->locate($gateway, $event);

        if (! $transaction || $transaction->isPaid()) {
            // Never walk back a payment already confirmed: a later failure event
            // for a captured payment is about a retry, not the money we hold.
            return $transaction;
        }

        $transaction->update([
            'status' => 'failed',
            'gateway_payload' => $event->raw,
        ]);

        return $transaction;
    }

    /** Find our row by payment id first (idempotency), then by our reference. */
    private function locate(string $gateway, WebhookEvent $event): ?Transaction
    {
        if ($event->paymentId) {
            $byPayment = Transaction::where('gateway', $gateway)
                ->where('gateway_payment_id', $event->paymentId)
                ->lockForUpdate()
                ->first();

            if ($byPayment) {
                return $byPayment;
            }
        }

        if ($event->reference) {
            $byReference = Transaction::where('reference', $event->reference)
                ->lockForUpdate()
                ->first();

            if ($byReference) {
                return $byReference;
            }
        }

        // The order id is the only identifier both sides are guaranteed to hold:
        // we store it when the order is created, and the gateway echoes it on the
        // payment. Notes can be absent or overwritten, so matching on them alone
        // silently drops real payments.
        if ($event->orderId) {
            $byOrder = Transaction::where('gateway', $gateway)
                ->where('gateway_order_id', $event->orderId)
                ->lockForUpdate()
                ->first();

            if ($byOrder) {
                return $byOrder;
            }
        }

        return null;
    }

    /** A recurring charge on an existing subscription: snapshot the donor again. */
    private function createRenewal(string $gateway, WebhookEvent $event): ?Transaction
    {
        if (! $event->subscriptionId) {
            return null;
        }

        $subscription = Subscription::where('gateway', $gateway)
            ->where('gateway_subscription_id', $event->subscriptionId)
            ->with('user')
            ->first();

        if (! $subscription) {
            return null;
        }

        // A guest subscription may not have been linked yet when its first
        // renewal lands; the donor snapshot still comes from the plan and the
        // linking job attaches the owner afterwards.
        $user = $subscription->user;

        return Transaction::create([
            'user_id' => $user?->id,
            'subscription_id' => $subscription->id,
            'gateway' => $gateway,
            'type' => 'subscription',
            'reference' => Transaction::newReference(),
            'amount' => $event->amount ?? $subscription->plan->amount,
            'currency' => $event->currency ?? $subscription->plan->currency,
            'status' => 'pending',
            'purpose' => $subscription->plan->name,
            'donor_name' => $user?->name ?: 'Donor',
            'donor_email' => $user?->email ?: '',
            'donor_phone' => $user?->phone,
            'donor_address' => $user?->full_address,
            'donor_pan' => $user?->pan,
        ]);
    }

    private function activateSubscription(Transaction $transaction, string $gateway, WebhookEvent $event): void
    {
        if (! $event->subscriptionId) {
            return;
        }

        $subscription = $transaction->subscription
            ?? Subscription::where('gateway', $gateway)
                ->where('gateway_subscription_id', $event->subscriptionId)
                ->first();

        if (! $subscription) {
            return;
        }

        $subscription->fill([
            'status' => 'active',
            'gateway_subscription_id' => $subscription->gateway_subscription_id ?: $event->subscriptionId,
            'started_at' => $subscription->started_at ?? now(),
        ])->save();

        if (! $transaction->subscription_id) {
            $transaction->update([
                'subscription_id' => $subscription->id,
                'type' => 'subscription',
            ]);
        }
    }

    /**
     * A guest paid, so there is no owner yet. Queued after commit so the worker
     * cannot read a row that has not been written.
     */
    private function linkDonorAccount(Transaction $transaction): void
    {
        if ($transaction->user_id) {
            return;   // already a signed-in donor's payment
        }

        LinkOrCreateDonorAccount::dispatch($transaction)->afterCommit();
    }

    /**
     * Queued after the surrounding transaction commits — dispatching inside it
     * can hand the job to a worker that then reads a row which does not exist yet.
     */
    private function emailReceipt(Transaction $transaction): void
    {
        if (! config('payments.receipts.email')) {
            return;   // n8n owns donor email on this deployment
        }

        SendDonationReceipt::dispatch($transaction)->afterCommit();
    }

    public function cancelSubscription(string $gateway, WebhookEvent $event): void
    {
        if (! $event->subscriptionId) {
            return;
        }

        Subscription::where('gateway', $gateway)
            ->where('gateway_subscription_id', $event->subscriptionId)
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    }
}
