<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Transaction;
use App\Payments\GatewayManager;
use App\Services\PaymentRecorder;
use App\Support\GuestCheckout;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __construct(private GatewayManager $gateways) {}

    /** Step 1 — choose a plan or a one-off amount. */
    public function choose()
    {
        return view('checkout.choose', [
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'presets' => config('payments.one_off.presets'),
            'currency' => config('payments.default_currency'),
        ]);
    }

    /** Step 2 — the donor details form, prefilled from the profile. */
    public function details(Request $request)
    {
        $input = $request->validate([
            'kind' => ['required', Rule::in(['plan', 'one_off'])],
            'plan' => ['required_if:kind,plan', 'nullable', 'exists:plans,slug'],
            'amount' => ['required_if:kind,one_off', 'nullable', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $plan = isset($input['plan']) ? Plan::where('slug', $input['plan'])->firstOrFail() : null;
        $currency = strtoupper($plan?->currency ?? $input['currency'] ?? config('payments.default_currency'));
        $amount = $plan
            ? $plan->amount
            : Money::toMinor((float) $input['amount'], $currency);

        $this->assertAmountWithinLimits($amount, $currency, $plan !== null);

        return view('checkout.details', [
            // A guest has nothing to prefill from; a blank model keeps the view
            // free of null checks on every field.
            'user' => $request->user() ?? new \App\Models\User,
            'plan' => $plan,
            'amount' => $amount,
            'currency' => $currency,
            'kind' => $input['kind'],
            // A gateway with no keys, or one that cannot take this currency, must
            // not be offered — the failure would otherwise happen mid-checkout.
            'gateways' => $this->gateways->available($currency),
        ]);
    }

    /** Step 3 — persist the donor details, create the transaction, hand off. */
    public function start(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['plan', 'one_off'])],
            'plan' => ['required_if:kind,plan', 'nullable', 'exists:plans,slug'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'gateway' => ['required', Rule::in(array_keys($this->gateways->all()))],

            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
            'pan' => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'purpose' => ['nullable', 'string', 'max:120'],
        ], [
            'pan.regex' => 'A PAN looks like ABCDE1234F.',
        ]);

        $user = $request->user();
        $isGuest = $user === null;
        $plan = isset($data['plan']) ? Plan::where('slug', $data['plan'])->firstOrFail() : null;
        $currency = strtoupper($data['currency']);

        // The amount is re-derived from the plan rather than trusted from the
        // form: a posted amount is user input, and a plan's price is not.
        $amount = $plan ? $plan->amount : (int) $data['amount'];
        $this->assertAmountWithinLimits($amount, $currency, $plan !== null);

        $gateway = $this->gateways->get($data['gateway']);

        if (! $gateway->isConfigured()) {
            throw ValidationException::withMessages([
                'gateway' => $gateway->displayName().' is not configured yet.',
            ]);
        }

        if (! in_array($currency, $gateway->supportedCurrencies(), true)) {
            throw ValidationException::withMessages([
                'gateway' => $gateway->displayName()." cannot take {$currency} here.",
            ]);
        }

        // Keep the profile current so the donor is not retyping this next time.
        // A guest has no profile yet — the account is created after payment.
        $user?->update(collect($data)->only([
            'name', 'phone', 'address_line1', 'address_line2',
            'city', 'state', 'postal_code', 'country', 'pan',
        ])->all());

        $address = collect([
            $data['address_line1'], $data['address_line2'] ?? null, $data['city'],
            $data['state'] ?? null, $data['postal_code'], $data['country'],
        ])->filter()->implode(', ');

        $transaction = Transaction::create([
            // Null for a guest; LinkOrCreateDonorAccount attaches an owner once
            // the payment is confirmed.
            'user_id' => $user?->id,
            'gateway' => $gateway->key(),
            'type' => $plan ? 'subscription' : 'one_off',
            'reference' => Transaction::newReference(),
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'purpose' => $data['purpose'] ?: ($plan?->name ?? 'General Donation'),
            // Snapshotted: the receipt must not change if the profile later does.
            'donor_name' => $data['name'],
            'donor_email' => $data['email'],
            'donor_phone' => $data['phone'],
            'donor_address' => $address,
            'donor_pan' => $data['pan'] ?? null,
        ]);

        try {
            $intent = $plan
                ? $gateway->startSubscription($transaction, $plan)
                : $gateway->startOneOff($transaction);
        } catch (\Throwable $e) {
            report($e);
            $transaction->update(['status' => 'failed']);

            return back()->withInput()->withErrors([
                'gateway' => 'We could not start the payment with '.$gateway->displayName().
                    '. Nothing has been charged. Please try again or pick another method.',
            ]);
        }

        if ($plan) {
            $transaction->subscription()->associate(
                \App\Models\Subscription::create([
                    'user_id' => $user?->id,
                    'plan_id' => $plan->id,
                    'gateway' => $gateway->key(),
                    'gateway_subscription_id' => $transaction->gateway_order_id,
                    'status' => 'pending',
                ])
            )->save();
        }

        if ($isGuest) {
            // Session is what lets this visitor back into their own payment;
            // they have no account to authorise them yet.
            GuestCheckout::remember($request, $transaction);
        }

        if ($intent->mode === 'redirect') {
            return redirect()->away($intent->redirectUrl);
        }

        return view('checkout.inline', [
            'transaction' => $transaction,
            'options' => $intent->inline,
            'gateway' => $gateway->key(),
        ]);
    }

    /** The browser comes back here. The webhook is still the source of truth. */
    public function return(Request $request, Transaction $transaction, PaymentRecorder $recorder)
    {
        abort_unless(GuestCheckout::authorises($request, $transaction), 403);

        if ($transaction->isPaid()) {
            return redirect()->route('transactions.show', $transaction);
        }

        $gateway = $this->gateways->get($transaction->gateway);

        try {
            $confirmed = $gateway->confirmReturn($transaction, $request);
        } catch (\Throwable $e) {
            report($e);
            $confirmed = false;
        }

        if (! $confirmed) {
            // Not necessarily a failure — the webhook may simply not have landed
            // yet, and it is authoritative. Say so rather than claiming failure.
            return redirect()->route('transactions.show', $transaction)
                ->with('status', 'We are still confirming this payment with '.
                    $gateway->displayName().'. This page updates as soon as it clears.');
        }

        $transaction->refresh();

        if (! $transaction->isPaid()) {
            $transaction->update(['status' => 'paid', 'paid_at' => now()]);
            $transaction->assignReceiptNumber();
        }

        return redirect()->route('transactions.show', $transaction)
            ->with('status', 'Thank you. Your receipt is ready to download.');
    }

    public function cancel(Request $request, Transaction $transaction)
    {
        abort_unless(GuestCheckout::authorises($request, $transaction), 403);

        if (! $transaction->isPaid()) {
            $transaction->update(['status' => 'cancelled']);
        }

        return redirect()->route('checkout.choose')
            ->with('status', 'Payment cancelled. Nothing has been charged.');
    }

    private function assertAmountWithinLimits(int $amount, string $currency, bool $isPlan): void
    {
        if ($isPlan) {
            return;   // plan prices are ours, not user input
        }

        $min = (int) config('payments.one_off.min');
        $max = (int) config('payments.one_off.max');

        if ($amount < $min || $amount > $max) {
            throw ValidationException::withMessages([
                'amount' => 'Please enter an amount between '.
                    Money::format($min, $currency).' and '.Money::format($max, $currency).'.',
            ]);
        }
    }
}
