<?php

namespace App\Payments\Gateways;

use App\Models\Plan;
use App\Models\Transaction;
use App\Payments\CheckoutIntent;
use App\Payments\PaymentGateway;
use App\Payments\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    public function key(): string
    {
        return 'stripe';
    }

    public function displayName(): string
    {
        return 'Stripe';
    }

    public function isConfigured(): bool
    {
        return filled(config('payments.stripe.secret_key'));
    }

    public function supportedCurrencies(): array
    {
        return ['INR', 'USD', 'EUR', 'GBP'];
    }

    private function http()
    {
        return Http::withToken(config('payments.stripe.secret_key'))
            ->asForm()
            ->timeout(20);
    }

    public function listPlans(): array
    {
        // Recurring prices only — a one-off price cannot back a subscription.
        // The product is expanded so the admin sees a name, not just price_….
        $response = $this->http()->get(self::API.'/prices', [
            'active' => 'true',
            'type' => 'recurring',
            'limit' => 100,
            'expand' => ['data.product'],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Stripe: '.($response->json()['error']['message'] ?? $response->status()));
        }

        return collect($response->json()['data'] ?? [])->map(fn ($price) => [
            'id' => $price['id'],
            'name' => $price['product']['name'] ?? ($price['nickname'] ?? $price['id']),
            'amount' => $price['unit_amount'] ?? null,
            'currency' => strtoupper((string) ($price['currency'] ?? '')),
            'cadence' => trim(($price['recurring']['interval_count'] ?? 1).'× '.($price['recurring']['interval'] ?? '')),
        ])->values()->all();
    }

    public function startOneOff(Transaction $transaction): CheckoutIntent
    {
        $session = $this->http()->post(self::API.'/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => route('checkout.return', $transaction).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('checkout.cancel', $transaction),
            'client_reference_id' => $transaction->reference,
            'customer_email' => $transaction->donor_email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($transaction->currency),
                    'unit_amount' => $transaction->amount,
                    'product_data' => ['name' => $transaction->purpose ?: 'Donation'],
                ],
            ]],
            // Echoed onto the PaymentIntent so the webhook can find our row even
            // if the session object itself is not the one delivered.
            'metadata' => [
                'reference' => $transaction->reference,
                'donor_name' => $transaction->donor_name,
                'donor_phone' => (string) $transaction->donor_phone,
            ],
            'payment_intent_data' => [
                'metadata' => ['reference' => $transaction->reference],
            ],
        ])->throw()->json();

        $transaction->update(['gateway_order_id' => $session['id']]);

        return CheckoutIntent::redirect($session['url']);
    }

    public function startSubscription(Transaction $transaction, Plan $plan): CheckoutIntent
    {
        $priceId = $plan->gatewayPlanId('stripe');

        if (! $priceId) {
            throw new \RuntimeException(
                "Plan '{$plan->slug}' has no Stripe price id. Create a recurring Price in Stripe and ".
                'put its price_… id in gateway_plan_ids.stripe.'
            );
        }

        $session = $this->http()->post(self::API.'/checkout/sessions', [
            'mode' => 'subscription',
            'success_url' => route('checkout.return', $transaction).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('checkout.cancel', $transaction),
            'client_reference_id' => $transaction->reference,
            'customer_email' => $transaction->donor_email,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'metadata' => ['reference' => $transaction->reference],
            'subscription_data' => [
                'metadata' => ['reference' => $transaction->reference],
            ],
        ])->throw()->json();

        $transaction->update(['gateway_order_id' => $session['id']]);

        return CheckoutIntent::redirect($session['url']);
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = config('payments.stripe.webhook_secret');
        $header = $request->header('Stripe-Signature');

        if (blank($secret) || blank($header)) {
            return false;
        }

        // Stripe-Signature is "t=<ts>,v1=<sig>,v1=<sig>"; the signed payload is
        // "<timestamp>.<raw body>", so the raw body is required — a re-encoded
        // array would not match.
        $parts = collect(explode(',', $header))
            ->map(fn ($p) => explode('=', trim($p), 2))
            ->filter(fn ($p) => count($p) === 2);

        $timestamp = $parts->firstWhere(0, 't')[1] ?? null;
        $signatures = $parts->where(0, 'v1')->map(fn ($p) => $p[1])->all();

        if (! $timestamp || empty($signatures)) {
            return false;
        }

        // Reject anything older than the tolerance so a captured request cannot
        // be replayed indefinitely.
        $tolerance = (int) config('payments.stripe.tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $body = $request->json()->all();
        $type = $body['type'] ?? '';
        $object = $body['data']['object'] ?? [];

        // checkout.session.completed covers one-offs and the first subscription
        // charge; invoice.paid covers every renewal after that. Between them the
        // whole lifecycle is receipted exactly once.
        if ($type === 'checkout.session.completed') {
            if (($object['payment_status'] ?? '') !== 'paid') {
                return WebhookEvent::ignored('session completed but not paid', $body);
            }

            return new WebhookEvent(
                type: 'payment_succeeded',
                reference: $object['client_reference_id'] ?? ($object['metadata']['reference'] ?? null),
                paymentId: $object['payment_intent'] ?? $object['id'] ?? null,
                orderId: $object['id'] ?? null,
                subscriptionId: $object['subscription'] ?? null,
                amount: $object['amount_total'] ?? null,
                currency: strtoupper((string) ($object['currency'] ?? '')),
                method: 'Card (Stripe)',
                status: 'paid',
                raw: $body,
            );
        }

        if ($type === 'invoice.paid') {
            // The first invoice is already covered by checkout.session.completed;
            // receipting it again would double-issue for one payment.
            if (($object['billing_reason'] ?? '') === 'subscription_create') {
                return WebhookEvent::ignored('first invoice handled via checkout.session.completed', $body);
            }

            return new WebhookEvent(
                type: 'payment_succeeded',
                reference: $object['subscription_details']['metadata']['reference'] ?? null,
                paymentId: $object['payment_intent'] ?? $object['id'] ?? null,
                subscriptionId: $object['subscription'] ?? null,
                amount: $object['amount_paid'] ?? null,
                currency: strtoupper((string) ($object['currency'] ?? '')),
                method: 'Card (Stripe)',
                status: 'paid',
                raw: $body,
            );
        }

        if (in_array($type, ['customer.subscription.deleted', 'customer.subscription.paused'], true)) {
            return new WebhookEvent(
                type: 'subscription_cancelled',
                subscriptionId: $object['id'] ?? null,
                raw: $body,
            );
        }

        if ($type === 'payment_intent.payment_failed') {
            return new WebhookEvent(
                type: 'payment_failed',
                reference: $object['metadata']['reference'] ?? null,
                paymentId: $object['id'] ?? null,
                raw: $body,
            );
        }

        return WebhookEvent::ignored("unhandled event: {$type}", $body);
    }

    public function confirmReturn(Transaction $transaction, Request $request): bool
    {
        $sessionId = $request->query('session_id') ?: $transaction->gateway_order_id;

        if (blank($sessionId)) {
            return false;
        }

        $session = $this->http()->get(self::API.'/checkout/sessions/'.$sessionId)->json();

        return ($session['payment_status'] ?? '') === 'paid';
    }
}
