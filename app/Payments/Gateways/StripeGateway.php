<?php

namespace App\Payments\Gateways;

use App\Models\Plan;
use App\Models\Transaction;
use App\Payments\CheckoutIntent;
use App\Payments\PaymentGateway;
use App\Payments\RemotePayment;
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

    public function fetchStatus(Transaction $transaction): ?WebhookEvent
    {
        $sessionId = $transaction->gateway_order_id;

        if (blank($sessionId)) {
            return null;
        }

        $response = $this->http()->get(self::API.'/checkout/sessions/'.$sessionId);

        if (! $response->successful()) {
            return null;
        }

        $session = $response->json();
        $paymentStatus = $session['payment_status'] ?? '';

        if ($paymentStatus === 'paid') {
            return $this->paidSessionEvent($session, ['source' => 'status-poll', 'session' => $session]);
        }

        // A session the donor abandoned expires rather than failing outright.
        if (($session['status'] ?? '') === 'expired') {
            return new WebhookEvent(
                type: 'payment_failed',
                reference: $session['client_reference_id'] ?? null,
                paymentId: $session['payment_intent'] ?? null,
                raw: ['source' => 'status-poll', 'session' => $session],
            );
        }

        return null;   // still open, donor may yet complete it
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

    /**
     * Identify a payment this app has no row for.
     *
     * The donor travels in the event itself — customer_details on a Checkout
     * Session, customer_email on an invoice — so identifying them costs nothing.
     * Only the plan may need a lookup: a subscription-mode session carries no
     * line items, and from API version 2025-03-31 an invoice line names its
     * price by id instead of embedding it. A failed lookup costs the plan, never
     * the donor, so the payment is still recorded and linked.
     */
    public function describePayment(WebhookEvent $event): ?RemotePayment
    {
        $object = $event->raw['data']['object'] ?? [];

        if (! $object) {
            return null;
        }

        if (($object['object'] ?? '') === 'payment_intent') {
            return $this->describePaymentIntent($object);
        }

        $email = $object['customer_details']['email'] ?? $object['customer_email'] ?? null;

        if (blank($email) && blank($event->subscriptionId)) {
            return null;
        }

        $line = $object['lines']['data'][0] ?? [];
        $price = $this->priceFor($object, $line, $event->subscriptionId);
        $product = is_array($price['product'] ?? null) ? $price['product'] : [];
        $planName = $product['name'] ?? ($line['description'] ?? ($price['nickname'] ?? null));

        return new RemotePayment(
            donorName: $object['customer_details']['name'] ?? $object['customer_name'] ?? null,
            donorEmail: $email,
            donorPhone: $object['customer_details']['phone'] ?? $object['customer_phone'] ?? null,
            donorAddress: $this->formatAddress($object['customer_details']['address'] ?? $object['customer_address'] ?? null),
            subscriptionId: $event->subscriptionId,
            planId: $price['id'] ?? null,
            planName: $planName,
            planAmount: isset($price['unit_amount']) ? (int) $price['unit_amount'] : null,
            planCurrency: strtoupper((string) ($price['currency'] ?? '')) ?: null,
            planInterval: match ($price['recurring']['interval'] ?? null) {
                'day' => 'daily',
                'week' => 'weekly',
                'month' => 'monthly',
                'year' => 'yearly',
                default => null,
            },
            planIntervalCount: $price['recurring']['interval_count'] ?? null,
            purpose: $planName ?? ($object['metadata']['purpose'] ?? null),
        );
    }

    /**
     * The donor behind a payment made without Checkout or an invoice.
     *
     * A PaymentIntent names its charge and customer only by id, so this is the
     * one Stripe path that needs lookups for the donor. The charge's billing
     * details come first — they are what the donor typed for this payment —
     * then the Customer, then receipt_email.
     *
     * @param  array<string, mixed>  $intent
     */
    private function describePaymentIntent(array $intent): ?RemotePayment
    {
        $charge = is_array($intent['latest_charge'] ?? null)
            ? $intent['latest_charge']
            : (($chargeId = $this->idOf($intent['latest_charge'] ?? null)) ? $this->fetch('/charges/'.$chargeId) : []);
        $billing = (array) ($charge['billing_details'] ?? []);

        $customer = is_array($intent['customer'] ?? null) ? $intent['customer'] : [];
        $customerId = $this->idOf($intent['customer'] ?? null);

        if (! $customer && $customerId && (blank($billing['email'] ?? null) || blank($billing['name'] ?? null))) {
            $customer = $this->fetch('/customers/'.$customerId);
        }

        $email = $billing['email'] ?? $customer['email'] ?? $intent['receipt_email'] ?? null;

        if (blank($email)) {
            return null;
        }

        return new RemotePayment(
            donorName: $billing['name'] ?? $customer['name'] ?? null,
            donorEmail: $email,
            donorPhone: $billing['phone'] ?? $customer['phone'] ?? null,
            donorAddress: $this->formatAddress($billing['address'] ?? $customer['address'] ?? null),
            purpose: $intent['metadata']['purpose'] ?? ($intent['description'] ?? null),
        );
    }

    /**
     * Whether a PaymentIntent is an invoice's payment, which invoice.paid
     * records under the invoice id. Recording it here too would receipt one
     * subscription charge twice.
     *
     * Before API version 2025-03-31 the intent names its invoice; from that
     * version it does not, and the link is only found through InvoicePayments.
     * A failed lookup throws rather than guessing: the delivery then fails and
     * Stripe retries it, where a wrong guess would either drop a payment or
     * receipt it twice.
     *
     * @param  array<string, mixed>  $intent
     */
    private function belongsToInvoice(array $intent): bool
    {
        if (array_key_exists('invoice', $intent)) {
            return filled($intent['invoice']);
        }

        return ! empty($this->http()->get(self::API.'/invoice_payments', [
            'payment' => ['type' => 'payment_intent', 'payment_intent' => $intent['id'] ?? ''],
            'limit' => 1,
        ])->throw()->json('data'));
    }

    /**
     * The recurring price behind a payment, or [] for a one-off.
     *
     * @param  array<string, mixed>  $object  a Checkout Session or an Invoice
     * @param  array<string, mixed>  $line  the invoice's first line, if any
     * @return array<string, mixed>
     */
    private function priceFor(array $object, array $line, ?string $subscriptionId): array
    {
        // Before 2025-03-31 the price is embedded in the invoice line.
        if (is_array($line['price'] ?? null)) {
            return $line['price'];
        }

        $priceId = $line['pricing']['price_details']['price'] ?? null;

        if (is_string($priceId)) {
            return $this->fetch('/prices/'.$priceId, ['expand' => ['product']]);
        }

        if ($subscriptionId && ($object['object'] ?? '') === 'checkout.session') {
            $subscription = $this->fetch('/subscriptions/'.$subscriptionId, ['expand' => ['items.data.price.product']]);

            return $subscription['items']['data'][0]['price'] ?? [];
        }

        return [];
    }

    /**
     * GET that answers [] instead of throwing: used only to enrich a payment
     * whose donor is already known.
     *
     * @return array<string, mixed>
     */
    private function fetch(string $path, array $query = []): array
    {
        $response = $this->http()->get(self::API.$path, $query);

        return $response->successful() ? (array) $response->json() : [];
    }

    private function formatAddress(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        $line = collect(['line1', 'line2', 'city', 'state', 'postal_code', 'country'])
            ->map(fn (string $key) => trim((string) ($address[$key] ?? '')))
            ->filter()
            ->implode(', ');

        return $line !== '' ? $line : null;
    }

    /**
     * A paid Checkout Session, whether delivered by webhook or found by polling.
     *
     * A subscription-mode session has no payment_intent of its own: its money
     * is the first invoice's. Keying it on that invoice id is what lets the
     * session and invoice.paid — both of which arrive for the first charge —
     * resolve to one payment instead of two receipts.
     *
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $raw
     */
    private function paidSessionEvent(array $session, array $raw): WebhookEvent
    {
        $paymentId = ($session['mode'] ?? '') === 'subscription'
            ? $this->idOf($session['invoice'] ?? null)
            : $this->idOf($session['payment_intent'] ?? null);

        return new WebhookEvent(
            type: 'payment_succeeded',
            reference: $session['client_reference_id'] ?? ($session['metadata']['reference'] ?? null),
            paymentId: $paymentId ?? ($session['id'] ?? null),
            orderId: $session['id'] ?? null,
            subscriptionId: $this->idOf($session['subscription'] ?? null),
            amount: $session['amount_total'] ?? null,
            currency: strtoupper((string) ($session['currency'] ?? '')),
            method: 'Card (Stripe)',
            status: 'paid',
            raw: $raw,
        );
    }

    /** Stripe returns either an id or, when expanded, the object carrying it. */
    private function idOf(mixed $value): ?string
    {
        $id = is_array($value) ? ($value['id'] ?? null) : $value;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Where an invoice keeps its subscription. API version 2025-03-31 moved it
     * under `parent`; the endpoint's pinned version decides which arrives.
     *
     * @param  array<string, mixed>  $invoice
     * @return array{0: ?string, 1: array<string, mixed>} [subscription id, its metadata]
     */
    private function invoiceSubscription(array $invoice): array
    {
        $details = $invoice['parent']['subscription_details'] ?? null;

        if (is_array($details)) {
            return [$this->idOf($details['subscription'] ?? null), (array) ($details['metadata'] ?? [])];
        }

        return [
            $this->idOf($invoice['subscription'] ?? null),
            (array) ($invoice['subscription_details']['metadata'] ?? []),
        ];
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $body = $request->json()->all();
        $type = $body['type'] ?? '';
        $object = $body['data']['object'] ?? [];

        // Checkout Sessions cover one-offs (ours and Payment Links alike) and a
        // subscription's first charge; invoice.paid covers every subscription
        // charge, the first included; payment_intent.succeeded covers payments
        // made with neither. The first charge therefore arrives twice,
        // under one invoice id, and is receipted once.
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            // Delayed methods complete the session unpaid, then send
            // async_payment_succeeded once the money actually lands.
            if (($object['payment_status'] ?? '') !== 'paid') {
                return WebhookEvent::ignored('session completed but not paid', $body);
            }

            return $this->paidSessionEvent($object, $body);
        }

        if ($type === 'checkout.session.async_payment_failed') {
            return new WebhookEvent(
                type: 'payment_failed',
                reference: $object['client_reference_id'] ?? ($object['metadata']['reference'] ?? null),
                paymentId: $this->idOf($object['payment_intent'] ?? null),
                orderId: $object['id'] ?? null,
                raw: $body,
            );
        }

        // Both fire for one invoice and either may be the one subscribed; the
        // shared invoice id makes the second a no-op.
        if (in_array($type, ['invoice.paid', 'invoice.payment_succeeded'], true)) {
            // A trial or a fully credited invoice is "paid" with no money moving,
            // and a receipt for nothing is a false document.
            if ((int) ($object['amount_paid'] ?? 0) <= 0) {
                return WebhookEvent::ignored('invoice paid with no money received', $body);
            }

            [$subscriptionId, $metadata] = $this->invoiceSubscription($object);

            return new WebhookEvent(
                type: 'payment_succeeded',
                reference: $metadata['reference'] ?? null,
                paymentId: $object['id'] ?? null,
                subscriptionId: $subscriptionId,
                amount: $object['amount_paid'],
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

        // Everything not taken through Checkout or an invoice: Dashboard
        // charges, the Payment Element, direct API payments. A Checkout
        // one-off sends this too, under the same pi_… id, so whichever of the
        // two lands second changes nothing.
        if ($type === 'payment_intent.succeeded') {
            if ($this->belongsToInvoice($object)) {
                return WebhookEvent::ignored('invoice payment recorded via invoice.paid', $body);
            }

            return new WebhookEvent(
                type: 'payment_succeeded',
                reference: $object['metadata']['reference'] ?? null,
                paymentId: $object['id'] ?? null,
                amount: $object['amount_received'] ?? ($object['amount'] ?? null),
                currency: strtoupper((string) ($object['currency'] ?? '')),
                method: 'Card (Stripe)',
                status: 'paid',
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
