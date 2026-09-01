<?php

namespace App\Support;

use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * A guest has no account, so "is this yours?" is answered by the session that
 * started the checkout. Deliberately not by donor_email: anyone could type an
 * address and read someone else's donation history.
 */
class GuestCheckout
{
    private const KEY = 'guest_transactions';

    public static function remember(Request $request, Transaction $transaction): void
    {
        $ids = $request->session()->get(self::KEY, []);
        $ids[] = $transaction->id;

        // Bounded so a long-lived session cannot grow without limit.
        $request->session()->put(self::KEY, array_slice(array_unique($ids), -20));
    }

    public static function owns(Request $request, Transaction $transaction): bool
    {
        return in_array($transaction->id, $request->session()->get(self::KEY, []), true);
    }

    /**
     * True when the caller may see this transaction: either they are the signed-in
     * owner, or they are the guest session that paid for it.
     */
    public static function authorises(Request $request, Transaction $transaction): bool
    {
        $user = $request->user();

        if ($user && $transaction->user_id === $user->id) {
            return true;
        }

        return self::owns($request, $transaction);
    }
}
