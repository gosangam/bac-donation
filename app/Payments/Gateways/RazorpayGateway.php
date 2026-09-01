<?php

namespace App\Payments\Gateways;

use App\Models\Plan;
use App\Models\Transaction;
use App\Payments\CheckoutIntent;
use App\Payments\PaymentGateway;
use App\Payments\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RazorpayGateway implements PaymentGateway
{
    private const API = 'https://api.razorpay.com/v1';

    public function key(): string
    {
        return 'razorpay';
    }

    public function displayName(): string
    {
        return 'Razorpay';
    }

    public function isConfigured(): bool
    {
        return filled(config('payments.razorpay.key_id')) && filled(config('payments.razorpay.key_secret'));
    }

    public function supportedCurrencies(): array
    {
        return ['INR'];
    }

    private function http()
    {
        return Http::withBasicAuth(
            config('payments.razorpay.key_id'),
            config('payments.razorpay.key_secret')
        )->acceptJson()->timeout(20);
    }

    public function listPlans(): array
    {
        $response = $this->http()->get(self::API.'/plans', ['count' => 100]);

        if (! $response->successful()) {
            throw new \RuntimeException('Razorpay: '.($response->json()['error']['description'] ?? $response->status()));
        }

        return collect($response->json()['items'] ?? [])->map(fn ($plan) => [
            'id' => $plan['id'],
            'name' => $plan['item']['name'] ?? $plan['id'],
            'amount' => $plan['item']['amount'] ?? null,
            'currency' => $plan['item']['currency'] ?? null,
            'cadence' => trim(($plan['interval'] ?? 1).'× '.($plan['period'] ?? '')),
        ])->values()->all();
    }

    public function startOneOff(Transaction $transaction): CheckoutIntent
    {
        $order = $this->http()->post(self::API.'/orders', [
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'receipt' => $transaction->reference,
            // Echoed back on the webhook, which is how we find our own row again.
            'notes' => [
                'reference' => $transaction->reference,
                'donor_name' => $transaction->donor_name,
                'purpose' => $transaction->purpose,
            ],
        ])->throw()->json();

        $transaction->update(['gateway_order_id' => $order['id']]);

        return CheckoutIntent::inline($this->checkoutOptions($transaction, ['order_id' => $order['id']]));
    }

    public function startSubscription(Transaction $transaction, Plan $plan): CheckoutIntent
    {
        $planId = $plan->gatewayPlanId('razorpay');

        if (! $planId) {
            throw new \RuntimeException(
                "Plan '{$plan->slug}' has no Razorpay plan id. Create the plan in Razorpay and put its ".
                'plan_… id in gateway_plan_ids.razorpay.'
            );
        }

        $subscription = $this->http()->post(self::API.'/subscriptions', [
            'plan_id' => $planId,
            // Razorpay requires a bound count; 120 monthly charges is 10 years,
            // effectively open-ended while still satisfying the API.
            'total_count' => config('payments.razorpay.total_count', 120),
            'customer_notify' => 1,
            'notes' => [
                'reference' => $transaction->reference,
                'donor_name' => $transaction->donor_name,
            ],
        ])->throw()->json();

        $transaction->update(['gateway_order_id' => $subscription['id']]);

        return CheckoutIntent::inline($this->checkoutOptions($transaction, [
            'subscription_id' => $subscription['id'],
        ]));
    }

    /** Options for checkout.js, including the donor details collected up front. */
    private function checkoutOptions(Transaction $transaction, array $extra): array
    {
        return array_merge([
            'key' => config('payments.razorpay.key_id'),
            'name' => config('payments.org.name'),
            'description' => $transaction->purpose ?: 'Donation',
            'image' => config('payments.org.logo_url'),
            'prefill' => [
                'name' => $transaction->donor_name,
                'email' => $transaction->donor_email,
                'contact' => $transaction->donor_phone,
            ],
            // Order notes do not reach the payment entity — these do. Without the
            // reference here the webhook arrives with notes.reference = null and
            // the payment cannot be tied back to its transaction.
            'notes' => [
                'reference' => $transaction->reference,
                'address' => $transaction->donor_address,
            ],
            'theme' => ['color' => config('payments.org.brand_color')],
        ], $extra);
    }

    public function fetchStatus(Transaction $transaction): ?WebhookEvent
    {
        // Best case: we already know the payment id, so ask about it directly.
        if ($transaction->gateway_payment_id) {
            $payment = $this->http()->get(self::API.'/payments/'.$transaction->gateway_payment_id);

            return $payment->successful() ? $this->eventFromPayment($payment->json()) : null;
        }

        $id = $transaction->gateway_order_id;

        if (blank($id)) {
            return null;   // never reached the gateway; nothing to ask about
        }

        // A subscription stores its sub_… id here, not an order id, and its
        // payments hang off invoices rather than the subscription itself.
        if (str_starts_with($id, 'sub_')) {
            return $this->statusFromSubscription($id);
        }

        $payments = $this->http()->get(self::API."/orders/{$id}/payments");

        if (! $payments->successful()) {
            return null;
        }

        return $this->pickPayment($payments->json()['items'] ?? []);
    }

    private function statusFromSubscription(string $subscriptionId): ?WebhookEvent
    {
        $invoices = $this->http()->get(self::API.'/invoices', [
            'subscription_id' => $subscriptionId,
            'count' => 10,
        ]);

        if (! $invoices->successful()) {
            return null;
        }

        foreach ($invoices->json()['items'] ?? [] as $invoice) {
            if (($invoice['status'] ?? '') === 'paid' && filled($invoice['payment_id'] ?? null)) {
                $payment = $this->http()->get(self::API.'/payments/'.$invoice['payment_id']);

                if ($payment->successful()) {
                    return $this->eventFromPayment($payment->json());
                }
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $payments */
    private function pickPayment(array $payments): ?WebhookEvent
    {
        // Captured beats authorised: only a capture is money we hold.
        foreach (['captured', 'authorized', 'failed'] as $status) {
            foreach ($payments as $payment) {
                if (($payment['status'] ?? '') === $status) {
                    return $this->eventFromPayment($payment);
                }
            }
        }

        return null;
    }

    private function eventFromPayment(array $payment): ?WebhookEvent
    {
        $status = $payment['status'] ?? '';

        // 'authorized' is money held but not taken; treating it as received
        // would issue a receipt for a payment that can still fall through.
        $type = match ($status) {
            'captured' => 'payment_succeeded',
            'failed' => 'payment_failed',
            default => null,
        };

        if (! $type) {
            return null;
        }

        return new WebhookEvent(
            type: $type,
            reference: $payment['notes']['reference'] ?? null,
            paymentId: $payment['id'] ?? null,
            orderId: $payment['order_id'] ?? null,
            subscriptionId: $payment['invoice_id'] ?? null,
            amount: $payment['amount'] ?? null,
            currency: $payment['currency'] ?? null,
            method: $this->describeMethod($payment),
            status: $status,
            raw: ['source' => 'status-poll', 'payment' => $payment],
        );
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = config('payments.razorpay.webhook_secret');
        $signature = $request->header('X-Razorpay-Signature');

        // No secret configured means Razorpay sends no signature at all, so there
        // is nothing to check — refuse rather than accept unverified money events.
        if (blank($secret) || blank($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $body = $request->json()->all();
        $event = $body['event'] ?? '';
        $payment = $body['payload']['payment']['entity'] ?? null;

        // Razorpay fires payment.captured AND subscription.charged for one
        // subscription charge. payment.captured covers every captured payment,
        // so the twin is ignored to avoid receipting the same money twice.
        if ($event === 'subscription.charged') {
            return WebhookEvent::ignored('already handled via payment.captured', $body);
        }

        if ($event === 'payment.failed' && $payment) {
            return new WebhookEvent(
                type: 'payment_failed',
                reference: $payment['notes']['reference'] ?? null,
                paymentId: $payment['id'] ?? null,
                raw: $body,
            );
        }

        if ($event !== 'payment.captured' || ! $payment) {
            return WebhookEvent::ignored("unhandled event: {$event}", $body);
        }

        return new WebhookEvent(
            type: 'payment_succeeded',
            reference: $payment['notes']['reference'] ?? null,
            paymentId: $payment['id'] ?? null,
            orderId: $payment['order_id'] ?? null,
            // invoice_id is set on subscription charges and null on one-offs,
            // which is the only reliable way to tell them apart here.
            subscriptionId: $payment['invoice_id'] ?? null,
            amount: $payment['amount'] ?? null,
            currency: $payment['currency'] ?? null,
            method: $this->describeMethod($payment),
            status: $payment['status'] ?? null,
            raw: $body,
        );
    }

    private function describeMethod(array $payment): string
    {
        return match ($payment['method'] ?? null) {
            'card' => trim(sprintf(
                '%s %s ••••%s',
                $payment['card']['network'] ?? 'Card',
                $payment['card']['type'] ?? '',
                $payment['card']['last4'] ?? ''
            )),
            'upi' => filled($payment['vpa'] ?? null) ? "UPI ({$payment['vpa']})" : 'UPI',
            'netbanking' => 'Netbanking'.(filled($payment['bank'] ?? null) ? " ({$payment['bank']})" : ''),
            default => ucfirst((string) ($payment['method'] ?? 'Razorpay')),
        };
    }

    public function confirmReturn(Transaction $transaction, Request $request): bool
    {
        // Checkout hands back razorpay_payment_id / _order_id / _signature. The
        // signature is HMAC(order_id|payment_id) with the API secret — a different
        // construction from the webhook's, and easy to confuse.
        $paymentId = $request->input('razorpay_payment_id');
        $orderId = $request->input('razorpay_order_id') ?: $transaction->gateway_order_id;
        $signature = $request->input('razorpay_signature');

        if (blank($paymentId) || blank($orderId) || blank($signature)) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $orderId.'|'.$paymentId,
            config('payments.razorpay.key_secret')
        );

        return hash_equals($expected, $signature);
    }
}
