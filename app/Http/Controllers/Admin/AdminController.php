<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReceiptRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function index()
    {
        // Totals are grouped by currency: summing INR and USD into one number
        // would be meaningless.
        $received = Transaction::where('status', 'paid')
            ->select('currency', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('currency')
            ->get();

        return view('admin.index', [
            'received' => $received,
            'donors' => User::count(),
            'activeSubscriptions' => Subscription::active()->count(),
            'pending' => Transaction::where('status', 'pending')->count(),
            'failed' => Transaction::where('status', 'failed')->count(),
            'recent' => Transaction::with('user')->latest()->limit(10)->get(),
            'byGateway' => Transaction::where('status', 'paid')
                ->select('gateway', 'currency', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
                ->groupBy('gateway', 'currency')
                ->get(),
        ]);
    }

    public function transactions(Request $request)
    {
        $query = Transaction::with('user')->latest();

        foreach (['gateway', 'status', 'type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('donor_name', 'like', "%{$search}%")
                    ->orWhere('donor_email', 'like', "%{$search}%")
                    ->orWhere('receipt_no', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('gateway_payment_id', 'like', "%{$search}%");
            });
        }

        return view('admin.transactions', [
            'transactions' => $query->paginate(30)->withQueryString(),
            'filters' => $request->only('gateway', 'status', 'type', 'q'),
        ]);
    }

    public function users()
    {
        return view('admin.users', [
            'users' => User::withCount('transactions')
                ->withSum(['transactions as paid_total' => fn ($q) => $q->where('status', 'paid')], 'amount')
                ->latest()
                ->paginate(30),
        ]);
    }

    /** One donor: their subscriptions and their payments, in one place. */
    public function showUser(User $user)
    {
        return view('admin.user', [
            'donor' => $user,
            'subscriptions' => $user->subscriptions()->with('plan')->latest()->get(),
            'transactions' => $user->transactions()->with('subscription.plan')->latest()->paginate(20),
            'totals' => Transaction::where('user_id', $user->id)
                ->where('status', 'paid')
                ->select('currency', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
                ->groupBy('currency')
                ->get(),
        ]);
    }

    /**
     * Plans that already exist inside a gateway, so an admin can pick one rather
     * than copying an id across from another dashboard and mistyping it.
     */
    public function gatewayPlans(string $gateway, \App\Payments\GatewayManager $gateways)
    {
        try {
            $driver = $gateways->get($gateway);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Unknown gateway.'], 404);
        }

        if (! $driver->isConfigured()) {
            return response()->json(['error' => $driver->displayName().' has no API keys configured.'], 422);
        }

        try {
            return response()->json(['plans' => $driver->listPlans()]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function subscriptions()
    {
        return view('admin.subscriptions', [
            'subscriptions' => Subscription::with('user', 'plan')->latest()->paginate(30),
        ]);
    }

    public function plans()
    {
        return view('admin.plans', ['plans' => Plan::orderBy('sort_order')->get()]);
    }

    public function storePlan(Request $request)
    {
        $data = $this->validatePlan($request);
        Plan::create($this->planAttributes($data));

        return back()->with('status', 'Plan created.');
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $data = $this->validatePlan($request, $plan);
        $plan->update($this->planAttributes($data));

        return back()->with('status', 'Plan updated.');
    }

    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash',
                \Illuminate\Validation\Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'amount_major' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            // Optional: without it the plan is simply not offered to foreign
            // donors, since PayPal cannot bill an Indian merchant in rupees.
            'amount_usd_major' => ['nullable', 'numeric', 'min:0.01'],
            'interval' => ['required', \Illuminate\Validation\Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'interval_count' => ['required', 'integer', 'min:1', 'max:12'],
            'razorpay_plan_id' => ['nullable', 'string', 'max:120'],
            'stripe_price_id' => ['nullable', 'string', 'max:120'],
            'paypal_plan_id' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function planAttributes(array $data): array
    {
        $currency = strtoupper($data['currency']);

        return [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'amount' => \App\Support\Money::toMinor((float) $data['amount_major'], $currency),
            'currency' => $currency,
            // Set independently of `amount` — a donation tier is a decision,
            // not a live FX conversion of the rupee price.
            'amount_usd' => filled($data['amount_usd_major'] ?? null)
                ? \App\Support\Money::toMinor((float) $data['amount_usd_major'], 'USD')
                : null,
            'interval' => $data['interval'],
            'interval_count' => $data['interval_count'],
            // Each gateway holds its own copy of the plan under its own id; this
            // app only stores the mapping.
            'gateway_plan_ids' => array_filter([
                'razorpay' => $data['razorpay_plan_id'] ?? null,
                'stripe' => $data['stripe_price_id'] ?? null,
                'paypal' => $data['paypal_plan_id'] ?? null,
            ]),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => $data['sort_order'] ?? 0,
        ];
    }

    /** Resend a receipt an admin can see failed, or that a donor lost. */
    public function resendReceipt(Transaction $transaction)
    {
        abort_unless($transaction->isPaid(), 404);

        if (blank($transaction->donor_email)) {
            return back()->withErrors(['resend' => 'That transaction has no donor email address.']);
        }

        // Cleared so the job's own guard does not treat this as a duplicate.
        $transaction->forceFill(['receipt_emailed_at' => null, 'receipt_email_error' => null])->save();

        \App\Jobs\SendDonationReceipt::dispatch($transaction);

        return back()->with('status', "Receipt {$transaction->receipt_no} queued for {$transaction->donor_email}.");
    }

    public function receipt(Transaction $transaction, ReceiptRenderer $renderer)
    {
        abort_unless($transaction->isPaid(), 404);

        return response($renderer->pdf($transaction), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$renderer->filename($transaction).'"',
        ]);
    }
}
