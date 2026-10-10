<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves subscriptions and payments between donor accounts by hand, for the
 * cases email matching gets wrong: a donor who paid from a work address, a
 * spouse paying for the household, a typo at the gateway.
 *
 * Only the owner changes. The donor_* snapshot on a transaction is what the
 * receipt was issued against, and an issued receipt must not change.
 */
class OwnershipController extends Controller
{
    /** Reassign a subscription, from the subscriptions list. A blank email unlinks it. */
    public function subscription(Request $request, Subscription $subscription): RedirectResponse
    {
        $owner = $this->ownerFromEmail($request);

        $this->moveSubscription($subscription, $owner);

        return back()->with('status', $owner
            ? "Subscription linked to {$owner->email}."
            : 'Subscription unlinked.');
    }

    /** Reassign one payment, from the transactions list. A blank email unlinks it. */
    public function transaction(Request $request, Transaction $transaction): RedirectResponse
    {
        $owner = $this->ownerFromEmail($request);

        $this->moveTransaction($transaction, $owner);

        return back()->with('status', $owner
            ? "Transaction linked to {$owner->email}."
            : 'Transaction unlinked.');
    }

    /** Attach a subscription to this donor, from their page, by its gateway id. */
    public function attachSubscription(Request $request, User $user): RedirectResponse
    {
        $identifier = trim((string) $request->validate([
            'subscription' => ['required', 'string', 'max:191'],
        ])['subscription']);

        $subscription = Subscription::where('gateway_subscription_id', $identifier)->first()
            ?? throw ValidationException::withMessages([
                'subscription' => 'No subscription has that gateway id.',
            ]);

        $this->moveSubscription($subscription, $user);

        return back()->with('status', 'Subscription linked.');
    }

    /** Attach a payment to this donor, from their page, by any id it carries. */
    public function attachTransaction(Request $request, User $user): RedirectResponse
    {
        $identifier = trim((string) $request->validate([
            'transaction' => ['required', 'string', 'max:191'],
        ])['transaction']);

        $transaction = Transaction::where('receipt_no', $identifier)
            ->orWhere('reference', $identifier)
            ->orWhere('gateway_payment_id', $identifier)
            ->orWhere('gateway_order_id', $identifier)
            ->first()
            ?? throw ValidationException::withMessages([
                'transaction' => 'No transaction has that receipt number, reference or payment id.',
            ]);

        $this->moveTransaction($transaction, $user);

        return back()->with('status', 'Transaction linked.');
    }

    /**
     * A subscription's payments move with it: a donor who can see a
     * subscription but none of what it charged would be looking at half a record.
     * Later renewals follow on their own, since they take the subscription's owner.
     */
    private function moveSubscription(Subscription $subscription, ?User $owner): void
    {
        DB::transaction(function () use ($subscription, $owner) {
            $subscription->update(['user_id' => $owner?->id]);

            $subscription->transactions()->update([
                'user_id' => $owner?->id,
                'linked_at' => $owner ? now() : null,
            ]);
        });
    }

    private function moveTransaction(Transaction $transaction, ?User $owner): void
    {
        $transaction->forceFill([
            'user_id' => $owner?->id,
            'linked_at' => $owner ? now() : null,
        ])->save();
    }

    private function ownerFromEmail(Request $request): ?User
    {
        $email = strtolower(trim((string) $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
        ])['email'] ?? ''));

        if ($email === '') {
            return null;
        }

        return User::whereRaw('lower(email) = ?', [$email])->first()
            ?? throw ValidationException::withMessages([
                'email' => 'No account uses that email. Create it under Donors first.',
            ]);
    }
}
