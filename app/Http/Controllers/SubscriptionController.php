<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Payments\GatewayManager;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        return view('subscriptions.index', [
            'subscriptions' => $request->user()->subscriptions()
                ->with('plan')
                ->latest()
                ->get(),
        ]);
    }

    public function show(Request $request, Subscription $subscription)
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);

        return view('subscriptions.show', [
            'subscription' => $subscription->load('plan'),
            'transactions' => $subscription->transactions()->latest()->get(),
        ]);
    }

    /**
     * Cancelling here only records intent and stops our side. The mandate itself
     * lives at the gateway, so it is cancelled there too — otherwise the donor
     * keeps being charged for something the dashboard says is cancelled.
     */
    public function cancel(Request $request, Subscription $subscription, GatewayManager $gateways)
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);

        if (! $subscription->isActive()) {
            return back()->with('status', 'That subscription is not active.');
        }

        try {
            $this->cancelAtGateway($subscription, $gateways);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'cancel' => 'We could not cancel this with '.ucfirst($subscription->gateway).
                    '. Nothing has been changed — please contact us so you are not charged again.',
            ]);
        }

        $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return back()->with('status', 'Subscription cancelled. You will not be charged again.');
    }

    private function cancelAtGateway(Subscription $subscription, GatewayManager $gateways): void
    {
        $id = $subscription->gateway_subscription_id;

        if (blank($id)) {
            return;   // never reached the gateway; nothing to cancel there
        }

        match ($subscription->gateway) {
            'razorpay' => \Illuminate\Support\Facades\Http::withBasicAuth(
                config('payments.razorpay.key_id'),
                config('payments.razorpay.key_secret')
            )->post("https://api.razorpay.com/v1/subscriptions/{$id}/cancel", [
                'cancel_at_cycle_end' => 0,
            ])->throw(),

            'stripe' => \Illuminate\Support\Facades\Http::withToken(
                config('payments.stripe.secret_key')
            )->asForm()->delete("https://api.stripe.com/v1/subscriptions/{$id}")->throw(),

            'paypal' => app(\App\Payments\Gateways\PayPalGateway::class)->cancelSubscription($id),

            default => throw new \RuntimeException("Cannot cancel on gateway {$subscription->gateway}"),
        };
    }
}
