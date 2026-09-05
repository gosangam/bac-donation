<?php

namespace App\Payments\Gateways;

use App\Models\Plan;
use App\Models\Transaction;
use App\Payments\CheckoutIntent;
use App\Payments\PaymentGateway;
use App\Payments\WebhookEvent;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PayPalGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'paypal';
    }

    public function displayName(): string
    {
        return 'PayPal';
    }

    public function isConfigured(): bool
    {
        return filled(config('payments.paypal.client_id')) && filled(config('payments.paypal.secret'));
    }

    public function supportedCurrencies(): array
    {
        // PayPal does not settle INR for most Indian accounts; USD is the norm
        // for foreign donations. Listing INR here would offer a checkout that fails.
        return ['USD', 'EUR', 'GBP'];
    }

    private function base(): string
    {
        return config('payments.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Tokens last ~9 hours; caching avoids an extra round trip on every call.
     *
     * The key covers the client id as well as the mode. Keying on mode alone
     * means swapping to a different REST app within sandbox keeps serving the
     * old app's token until the entry expires — so the new credentials appear
     * to do nothing, and calls silently read the previous app's account.
     */
    private function accessToken(): string
    {
        $mode = config('payments.paypal.mode');
        $client = substr(hash('sha256', (string) config('payments.paypal.client_id')), 0, 12);

        return Cache::remember("paypal.token.{$mode}.{$client}", now()->addMinutes(30), function () {
            $response = Http::asForm()
                ->withBasicAuth(config('payments.paypal.client_id'), config('payments.paypal.secret'))
                ->timeout(20)
                ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
                ->throw()
                ->json();

            return $response['access_token'];
        });
    }

    private function http()
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout(20);
    }

    public function listPlans(): array
    {
        $response = $this->http()->get($this->base().'/v1/billing/plans', [
            'page_size' => 20,
            'total_required' => 'true',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('PayPal: '.($response->json()['message'] ?? $response->status()));
        }

        // PayPal's list response carries no pricing — billing_cycles only appear
        // on the detail endpoint — so amount and currency are left null rather
        // than fetching N extra requests to fill a dropdown.
        return collect($response->json()['plans'] ?? [])
            ->filter(fn ($plan) => ($plan['status'] ?? '') === 'ACTIVE')
            ->map(fn ($plan) => [
                'id' => $plan['id'],
                'name' => $plan['name'] ?? $plan['id'],
                'amount' => null,
                'currency' => null,
                'cadence' => null,
            ])->values()->all();
    }

    public function startOneOff(Transaction $transaction): CheckoutIntent
    {
        $order = $this->http()->post($this->base().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $transaction->reference,
                'custom_id' => $transaction->purpose ?: 'Donation',
                'invoice_id' => $transaction->reference,
                'description' => \Illuminate\Support\Str::limit($transaction->purpose ?: 'Donation', 120, ''),
                'amount' => [
                    'currency_code' => $transaction->currency,
                    'value' => number_format(
                        Money::toDecimal($transaction->amount, $transaction->currency),
                        2, '.', ''
                    ),
                ],
            ]],
            // Prefilled so the donor is not asked for details twice.
            'payer' => $this->payerPayload($transaction),
            'application_context' => [
                'brand_name' => config('payments.org.name'),
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => route('checkout.return', $transaction),
                'cancel_url' => route('checkout.cancel', $transaction),
            ],
        ])->throw()->json();

        $transaction->update(['gateway_order_id' => $order['id']]);

        return CheckoutIntent::redirect($this->approvalLink($order));
    }

    public function startSubscription(Transaction $transaction, Plan $plan): CheckoutIntent
    {
        $planId = $plan->gatewayPlanId('paypal');

        if (! $planId) {
            throw new \RuntimeException(
                "Plan '{$plan->slug}' has no PayPal plan id. Create a billing plan in PayPal and put ".
                'its P-… id in gateway_plan_ids.paypal.'
            );
        }

        $subscription = $this->http()->post($this->base().'/v1/billing/subscriptions', [
            'plan_id' => $planId,
            'custom_id' => $transaction->reference,
            'subscriber' => $this->payerPayload($transaction),
            'application_context' => [
                'brand_name' => config('payments.org.name'),
                'user_action' => 'SUBSCRIBE_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => route('checkout.return', $transaction),
                'cancel_url' => route('checkout.cancel', $transaction),
            ],
        ])->throw()->json();

        $transaction->update(['gateway_order_id' => $subscription['id']]);

        return CheckoutIntent::redirect($this->approvalLink($subscription));
    }

    private function payerPayload(Transaction $transaction): array
    {
        $parts = preg_split('/\s+/', trim($transaction->donor_name), 2);

        return array_filter([
            'name' => array_filter([
                'given_name' => $parts[0] ?? null,
                'surname' => $parts[1] ?? null,
            ]),
            'email_address' => $transaction->donor_email,
        ]);
    }

    private function approvalLink(array $resource): string
    {
        foreach ($resource['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)) {
                return $link['href'];
            }
        }

        throw new \RuntimeException('PayPal returned no approval link: '.json_encode($resource));
    }

    public function fetchStatus(Transaction $transaction): ?WebhookEvent
    {
        $id = $transaction->gateway_order_id;

        if (blank($id)) {
            return null;
        }

        // Subscription ids start with I-; orders do not.
        return str_starts_with($id, 'I-')
            ? $this->statusFromSubscription($id)
            : $this->statusFromOrder($id);
    }

    private function statusFromOrder(string $orderId): ?WebhookEvent
    {
        $response = $this->http()->get($this->base()."/v2/checkout/orders/{$orderId}");

        if (! $response->successful()) {
            return null;
        }

        $order = $response->json();
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        // APPROVED means the donor agreed but nothing was captured, so no money
        // has moved and there is nothing to receipt yet.
        if (($order['status'] ?? '') !== 'COMPLETED' || ! $capture) {
            return null;
        }

        $currency = $capture['amount']['currency_code'] ?? 'USD';

        return new WebhookEvent(
            type: 'payment_succeeded',
            reference: $capture['invoice_id'] ?? $capture['custom_id'] ?? null,
            paymentId: $capture['id'] ?? null,
            orderId: $orderId,
            amount: Money::toMinor((float) ($capture['amount']['value'] ?? 0), $currency),
            currency: $currency,
            method: 'PayPal',
            status: 'paid',
            raw: ['source' => 'status-poll', 'order' => $order],
        );
    }

    private function statusFromSubscription(string $subscriptionId): ?WebhookEvent
    {
        $response = $this->http()->get($this->base()."/v1/billing/subscriptions/{$subscriptionId}");

        if (! $response->successful()) {
            return null;
        }

        $subscription = $response->json();
        $status = $subscription['status'] ?? '';

        if (in_array($status, ['CANCELLED', 'SUSPENDED', 'EXPIRED'], true)) {
            return new WebhookEvent(
                type: 'subscription_cancelled',
                subscriptionId: $subscriptionId,
                raw: ['source' => 'status-poll', 'subscription' => $subscription],
            );
        }

        if ($status !== 'ACTIVE') {
            return null;
        }

        // The subscription object's last_payment carries no transaction id, and
        // inventing one would let the real webhook look like a different payment
        // and issue a second receipt. Ask for the actual transactions instead.
        $transactions = $this->http()->get(
            $this->base()."/v1/billing/subscriptions/{$subscriptionId}/transactions",
            [
                'start_time' => $subscription['create_time'] ?? now()->subYear()->toIso8601ZuluString(),
                'end_time' => now()->addMinute()->toIso8601ZuluString(),
            ]
        );

        if (! $transactions->successful()) {
            return null;
        }

        foreach ($transactions->json()['transactions'] ?? [] as $txn) {
            if (($txn['status'] ?? '') !== 'COMPLETED') {
                continue;
            }

            $currency = $txn['amount_with_breakdown']['gross_amount']['currency_code'] ?? 'USD';
            $value = $txn['amount_with_breakdown']['gross_amount']['value'] ?? 0;

            return new WebhookEvent(
                type: 'payment_succeeded',
                paymentId: $txn['id'] ?? null,
                subscriptionId: $subscriptionId,
                amount: Money::toMinor((float) $value, $currency),
                currency: $currency,
                method: 'PayPal',
                status: 'paid',
                raw: ['source' => 'status-poll', 'transaction' => $txn],
            );
        }

        return null;
    }

    /**
     * PayPal signs with RSA over a CRC32 of the body, not an HMAC, so there is
     * nothing to recompute locally — the signature is posted back to PayPal to
     * be checked. Wrong webhook id or wrong environment both fail here.
     */
    public function verifyWebhook(Request $request): bool
    {
        $webhookId = config('payments.paypal.webhook_id');

        if (blank($webhookId)) {
            return false;
        }

        $headers = [
            'auth_algo' => $request->header('paypal-auth-algo'),
            'cert_url' => $request->header('paypal-cert-url'),
            'transmission_id' => $request->header('paypal-transmission-id'),
            'transmission_sig' => $request->header('paypal-transmission-sig'),
            'transmission_time' => $request->header('paypal-transmission-time'),
        ];

        if (in_array(null, $headers, true) || in_array('', $headers, true)) {
            return false;
        }

        $response = $this->http()
            ->post($this->base().'/v1/notifications/verify-webhook-signature', $headers + [
                'webhook_id' => $webhookId,
                'webhook_event' => $request->json()->all(),
            ]);

        return $response->successful() && ($response->json()['verification_status'] ?? '') === 'SUCCESS';
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $body = $request->json()->all();
        $type = $body['event_type'] ?? '';
        $resource = $body['resource'] ?? [];

        // Only events where money actually moved. CHECKOUT.ORDER.APPROVED and
        // BILLING.SUBSCRIPTION.ACTIVATED move none, so receipting them would
        // issue a tax document for a payment that has not happened.
        if (in_array($type, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.SALE.COMPLETED'], true)) {
            $currency = $resource['amount']['currency_code'] ?? $resource['amount']['currency'] ?? 'USD';
            $value = $resource['amount']['value'] ?? $resource['amount']['total'] ?? '0';

            return new WebhookEvent(
                type: 'payment_succeeded',
                // invoice_id carries our reference on one-offs; custom_id does on
                // subscriptions, where PayPal has no invoice of ours to echo.
                reference: $resource['invoice_id'] ?? $resource['custom_id'] ?? null,
                paymentId: $resource['id'] ?? null,
                orderId: $resource['supplementary_data']['related_ids']['order_id'] ?? null,
                subscriptionId: $resource['billing_agreement_id'] ?? null,
                amount: Money::toMinor((float) $value, $currency),
                currency: $currency,
                method: 'PayPal',
                status: 'paid',
                raw: $body,
            );
        }

        if (in_array($type, ['BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED'], true)) {
            return new WebhookEvent(
                type: 'subscription_cancelled',
                subscriptionId: $resource['id'] ?? null,
                raw: $body,
            );
        }

        return WebhookEvent::ignored("unhandled event: {$type}", $body);
    }

    /** Cancels the mandate at PayPal so the donor stops being charged. */
    public function cancelSubscription(string $subscriptionId): void
    {
        $this->http()
            ->post($this->base()."/v1/billing/subscriptions/{$subscriptionId}/cancel", [
                'reason' => 'Cancelled by donor from the dashboard',
            ])
            ->throw();
    }

    public function confirmReturn(Transaction $transaction, Request $request): bool
    {
        // A one-off order is only approved at this point; capture is what takes
        // the money, and PayPal will not do it for us.
        $token = $request->query('token') ?: $transaction->gateway_order_id;

        if (blank($token) || $transaction->type !== 'one_off') {
            return $transaction->type === 'subscription';
        }

        $response = $this->http()->post($this->base()."/v2/checkout/orders/{$token}/capture", (object) []);

        if ($response->status() === 422) {
            // ORDER_ALREADY_CAPTURED — the webhook beat the browser back. Not an error.
            return str_contains($response->body(), 'ORDER_ALREADY_CAPTURED');
        }

        return $response->successful() && ($response->json()['status'] ?? '') === 'COMPLETED';
    }
}
