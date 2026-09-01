<?php

namespace App\Http\Controllers;

use App\Payments\GatewayManager;
use App\Services\PaymentRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private GatewayManager $gateways,
        private PaymentRecorder $recorder,
    ) {}

    public function handle(Request $request, string $gateway)
    {
        try {
            $driver = $this->gateways->get($gateway);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'unknown gateway'], 404);
        }

        if (! $driver->verifyWebhook($request)) {
            // Fails closed. This endpoint marks money as received, so anything
            // that cannot be positively verified is refused — including the case
            // where no webhook secret is configured at all.
            Log::warning('Rejected unverified webhook', [
                'gateway' => $gateway,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'signature verification failed'], 400);
        }

        $event = $driver->parseWebhook($request);

        // Acknowledge quickly regardless of outcome: a non-2xx makes the gateway
        // retry, and retrying will not fix an event we simply do not act on.
        match ($event->type) {
            'payment_succeeded' => $this->recorder->recordSuccess($gateway, $event),
            'payment_failed' => $this->recorder->recordFailure($gateway, $event),
            'subscription_cancelled' => $this->recorder->cancelSubscription($gateway, $event),
            default => Log::info('Webhook ignored', [
                'gateway' => $gateway,
                'reason' => $event->reason,
            ]),
        };

        return response()->json(['status' => 'ok']);
    }
}
