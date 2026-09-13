<?php

namespace App\Services;

use App\Jobs\LinkOrCreateDonorAccount;
use App\Jobs\SendDonationReceipt;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Payments\GatewayManager;
use App\Payments\RemotePayment;
use App\Payments\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single place a payment is marked received.
 *
 * Both the webhook and the browser-return leg call this, and gateways retry
 * webhooks, so it must be safe to call repeatedly for the same payment. It is
 * keyed on the gateway's payment id: that is stable across retries and across
 * the two different events some gateways send for one charge.
 *
 * It also has to cope with money arriving for things it has never heard of.
 * Subscriptions set up before this dashboard existed — or created directly in a
 * gateway's console — keep charging, and their webhooks reference nothing local.
 * Those are adopted rather than dropped: the gateway is asked who paid, a
 * transaction is written from the answer, and the donor is linked to an account
 * or given one.
 */
class PaymentRecorder
{
    public function __construct(private GatewayManager $gateways) {}

    public function recordSuccess(string $gateway, WebhookEvent $event): ?Transaction
    {
        // Resolved before the write transaction opens. Identifying an unknown
        // payment costs API round trips, and holding a database transaction
        // across those would block every other writer for the duration.
        $remote = $this->describeIfUnknown($gateway, $event);

        return DB::transaction(function () use ($gateway, $event, $remote) {
            $transaction = $this->locate($gateway, $event)
                ?? $this->createFromGateway($gateway, $event, $remote);

            if (! $transaction) {
                Log::warning('Payment could not be matched to a transaction', [
                    'gateway' => $gateway,
                    'payment_id' => $event->paymentId,
                    'reference' => $event->reference,
                    'subscription_id' => $event->subscriptionId ?: $remote?->subscriptionId,
                    'identified' => $remote !== null,
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

            $this->activateSubscription($transaction, $gateway, $event, $remote);

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

            $this->activateSubscription($transaction, $gateway, $event, null);
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

            if ($byReference && $this->isSamePayment($byReference, $event)) {
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

            if ($byOrder && $this->isSamePayment($byOrder, $event)) {
                return $byOrder;
            }
        }

        return null;
    }

    /**
     * Whether a row found by reference or order id is really this payment.
     *
     * Razorpay copies a subscription's notes — our reference among them — onto
     * every renewal payment, and a subscription keeps its sub_… id in
     * gateway_order_id for its whole life. Matching on either alone therefore
     * returns the FIRST charge for every later one, and because that row is
     * already paid the renewal is silently swallowed: no transaction, no
     * receipt. A row settled against a different payment is a different payment.
     */
    private function isSamePayment(Transaction $transaction, WebhookEvent $event): bool
    {
        return blank($transaction->gateway_payment_id)
            || $transaction->gateway_payment_id === $event->paymentId;
    }

    /**
     * Ask the gateway who paid — but only for a payment we hold no row for.
     *
     * The check is deliberately lock-free and approximate: it is a fast path to
     * avoid pointless API calls, not the decision itself, which locate() still
     * makes inside the write transaction.
     */
    private function describeIfUnknown(string $gateway, WebhookEvent $event): ?RemotePayment
    {
        if (! config('payments.adopt_unknown_payments', true)) {
            return null;
        }

        if ($this->alreadyHeld($gateway, $event)) {
            return null;
        }

        try {
            $driver = $this->gateways->get($gateway);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (! $driver->isConfigured()) {
            return null;
        }

        try {
            return $driver->describePayment($event);
        } catch (\Throwable $e) {
            // A gateway that will not answer must not cost us the webhook: the
            // event still gets its normal handling, which logs and drops it.
            Log::warning('Could not identify an unknown payment', [
                'gateway' => $gateway,
                'payment_id' => $event->paymentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function alreadyHeld(string $gateway, WebhookEvent $event): bool
    {
        if ($event->paymentId && Transaction::where('gateway', $gateway)
            ->where('gateway_payment_id', $event->paymentId)->exists()) {
            return true;
        }

        // Only counts as held if that row is still unsettled — a reference match
        // against an already-paid row is a renewal, which is not held at all.
        if ($event->reference && Transaction::where('reference', $event->reference)
            ->whereNull('gateway_payment_id')->exists()) {
            return true;
        }

        return (bool) ($event->orderId && Transaction::where('gateway', $gateway)
            ->where('gateway_order_id', $event->orderId)
            ->whereNull('gateway_payment_id')->exists());
    }

    /**
     * Build a transaction for money that arrived without one.
     *
     * Covers three cases with the same code: a renewal of a subscription we
     * know, a charge on a subscription we have never seen, and a one-off taken
     * outside the dashboard entirely.
     */
    private function createFromGateway(string $gateway, WebhookEvent $event, ?RemotePayment $remote): ?Transaction
    {
        $subscriptionId = $event->subscriptionId ?: $remote?->subscriptionId;

        $subscription = $subscriptionId
            ? Subscription::where('gateway', $gateway)
                ->where('gateway_subscription_id', $subscriptionId)
                ->with('user', 'plan')
                ->first()
            : null;

        // Never seen it: set up in the gateway's own console, or before this
        // dashboard existed. Adopt it so the donor gets a history and a receipt.
        if (! $subscription && $remote?->isSubscription()) {
            $subscription = $this->adoptSubscription($gateway, $remote);
        }

        if (! $subscription && ! $remote) {
            return null;   // nothing to build a row from
        }

        $amount = $event->amount ?: ($subscription?->plan?->amount ?: $remote?->planAmount);
        $currency = $event->currency ?: ($subscription?->plan?->currency ?: $remote?->planCurrency);

        if (! $amount || ! $currency) {
            Log::warning('Cannot record a payment with no amount', [
                'gateway' => $gateway,
                'payment_id' => $event->paymentId,
            ]);

            return null;
        }

        // A guest subscription may still be unlinked when its renewal lands; the
        // linking job attaches the owner afterwards either way.
        $user = $subscription?->user ?? $this->existingDonor($remote?->donorEmail);

        // Recurring if anything says so — an adopted subscription, the gateway's
        // answer, or Razorpay's invoice id on the payment itself.
        $isRecurring = $subscription || $remote?->isSubscription() || filled($event->invoiceId);

        return Transaction::create([
            'user_id' => $user?->id,
            'subscription_id' => $subscription?->id,
            'gateway' => $gateway,
            'type' => $isRecurring ? 'subscription' : 'one_off',
            'reference' => Transaction::newReference(),
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'purpose' => $remote?->purpose ?: ($subscription?->plan?->name ?: 'Donation'),
            // The gateway's copy of the donor beats ours: it is what they typed
            // at the gateway, and for an adopted payment it is all we have.
            'donor_name' => $remote?->donorName ?: ($user?->name ?: 'Donor'),
            'donor_email' => $remote?->donorEmail ?: ($user?->email ?: ''),
            'donor_phone' => $remote?->donorPhone ?: $user?->phone,
            'donor_address' => $remote?->donorAddress ?: $user?->full_address,
            'donor_id_type' => $user?->id_type,
            'donor_id_number' => $user?->id_number,
        ]);
    }

    /**
     * Mirror a gateway-side subscription locally.
     *
     * firstOrCreate against the (gateway, gateway_subscription_id) unique index:
     * two webhooks for the same subscription can land at once, and only one row
     * may result.
     */
    private function adoptSubscription(string $gateway, RemotePayment $remote): ?Subscription
    {
        $plan = $this->resolvePlan($gateway, $remote);

        if (! $plan) {
            // subscriptions.plan_id is required, so without a plan the charge is
            // still recorded — just not tied to a subscription.
            Log::warning('Adopted a payment but could not resolve its plan', [
                'gateway' => $gateway,
                'subscription_id' => $remote->subscriptionId,
                'plan_id' => $remote->planId,
            ]);

            return null;
        }

        return Subscription::firstOrCreate(
            ['gateway' => $gateway, 'gateway_subscription_id' => $remote->subscriptionId],
            [
                'user_id' => $this->existingDonor($remote->donorEmail)?->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'started_at' => now(),
                'meta' => [
                    'adopted' => true,
                    'adopted_at' => now()->toIso8601String(),
                ],
            ]
        );
    }

    /**
     * The local plan a gateway plan id maps to, creating a mirror if there is
     * none. The mirror is inactive: it exists so an adopted subscription has
     * something to point at and so an admin can see and tidy it, but it must
     * never appear as something a new donor can choose.
     */
    private function resolvePlan(string $gateway, RemotePayment $remote): ?Plan
    {
        if (blank($remote->planId)) {
            return null;
        }

        // Plans number in the handful, so this is a cheap scan and avoids the
        // JSON-path differences between SQLite and MySQL.
        $existing = Plan::all()
            ->first(fn (Plan $plan) => $plan->gatewayPlanId($gateway) === $remote->planId);

        if ($existing) {
            return $existing;
        }

        $name = $remote->planName ?: 'Imported plan';

        return Plan::create([
            'slug' => $this->uniqueSlug($name, $remote->planId),
            'name' => $name,
            'description' => 'Imported from '.$gateway.' when an existing subscription was charged.',
            'amount' => $remote->planAmount ?: 0,
            'currency' => $remote->planCurrency ?: config('payments.default_currency'),
            'interval' => in_array($remote->planInterval, ['daily', 'weekly', 'monthly', 'yearly'], true)
                ? $remote->planInterval
                : 'monthly',
            'interval_count' => $remote->planIntervalCount ?: 1,
            'gateway_plan_ids' => [$gateway => $remote->planId],
            'is_active' => false,
            'sort_order' => 999,
        ]);
    }

    private function uniqueSlug(string $name, string $planId): string
    {
        // The gateway id is unique, so suffixing with it cannot collide even when
        // two gateways hold plans of the same name.
        $slug = Str::slug($name).'-'.Str::lower(Str::substr($planId, -6));

        return Plan::where('slug', $slug)->exists()
            ? $slug.'-'.Str::lower(Str::random(4))
            : $slug;
    }

    private function existingDonor(?string $email): ?User
    {
        if (blank($email)) {
            return null;
        }

        // lower() in SQL rather than Eloquent's where: emails are
        // case-insensitive in practice and SQLite/MySQL differ on collation.
        return User::whereRaw('lower(email) = ?', [Str::lower(trim($email))])->first();
    }

    private function activateSubscription(
        Transaction $transaction,
        string $gateway,
        WebhookEvent $event,
        ?RemotePayment $remote,
    ): void {
        $subscriptionId = $event->subscriptionId ?: $remote?->subscriptionId;

        $subscription = $transaction->subscription
            ?? ($subscriptionId
                ? Subscription::where('gateway', $gateway)
                    ->where('gateway_subscription_id', $subscriptionId)
                    ->first()
                : null);

        if (! $subscription) {
            return;
        }

        $subscription->fill([
            'status' => 'active',
            'gateway_subscription_id' => $subscription->gateway_subscription_id ?: $subscriptionId,
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
