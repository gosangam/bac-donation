<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\DonorAccountCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Attaches a guest donation to a donor account, creating one if needed.
 *
 * Runs after the payment is confirmed, never before: an account created from an
 * abandoned checkout is just litter, and we do not want to email a set-password
 * link to someone who never actually gave.
 */
class LinkOrCreateDonorAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public Transaction $transaction) {}

    public function handle(): void
    {
        $transaction = $this->transaction->fresh();

        if (! $transaction || ! $transaction->isPaid() || $transaction->user_id) {
            return;   // not paid, or already attached to an account
        }

        $email = strtolower(trim($transaction->donor_email));

        if (blank($email)) {
            Log::warning('Guest donation has no email; cannot create an account', [
                'transaction' => $transaction->id,
            ]);

            return;
        }

        [$user, $wasCreated] = $this->findOrCreateUser($email, $transaction);

        $this->attach($transaction, $user);

        // Only a brand-new account gets a set-password mail. Sending one to an
        // existing donor would look like an unrequested password reset — and
        // worse, it would confirm to whoever typed the address that the account
        // exists.
        if ($wasCreated) {
            $user->notify(new DonorAccountCreated(Password::broker()->createToken($user)));
        }
    }

    /** @return array{0: User, 1: bool} */
    private function findOrCreateUser(string $email, Transaction $transaction): array
    {
        // whereRaw on lower() rather than Eloquent's where: emails are
        // case-insensitive in practice, and SQLite/MySQL differ on collation.
        $existing = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($existing) {
            $this->backfillMissingProfileFields($existing, $transaction);

            return [$existing, false];
        }

        $user = new User;
        $user->forceFill([
            'name' => $transaction->donor_name ?: 'Donor',
            'email' => $email,
            // Unguessable and never sent anywhere; the donor sets their own via
            // the reset link. A null password would break Auth::attempt oddly.
            'password' => Str::password(32),
            'phone' => $transaction->donor_phone,
            'pan' => $transaction->donor_pan,
        ] + $this->addressFrom($transaction));
        $user->save();

        return [$user, true];
    }

    /** Fill only blanks — a guest form must not overwrite a donor's saved profile. */
    private function backfillMissingProfileFields(User $user, Transaction $transaction): void
    {
        $fill = array_filter([
            'phone' => $user->phone ?: $transaction->donor_phone,
            'pan' => $user->pan ?: $transaction->donor_pan,
        ]);

        if ($fill) {
            $user->forceFill($fill)->save();
        }
    }

    /**
     * The address is stored on the transaction as one line, so it cannot be
     * split back into fields reliably. Only used when the account is new and has
     * nothing better.
     */
    private function addressFrom(Transaction $transaction): array
    {
        return blank($transaction->donor_address)
            ? []
            : ['address_line1' => Str::limit($transaction->donor_address, 255, '')];
    }

    private function attach(Transaction $transaction, User $user): void
    {
        DB::transaction(function () use ($transaction, $user) {
            $transaction->forceFill([
                'user_id' => $user->id,
                'linked_at' => now(),
            ])->save();

            // Any subscription created during the same guest checkout.
            if ($transaction->subscription_id) {
                Subscription::whereKey($transaction->subscription_id)
                    ->whereNull('user_id')
                    ->update(['user_id' => $user->id]);
            }

            // Earlier guest donations from the same address, so the donor's
            // history is complete the first time they sign in.
            Transaction::whereNull('user_id')
                ->whereRaw('lower(donor_email) = ?', [strtolower($user->email)])
                ->update(['user_id' => $user->id, 'linked_at' => now()]);
        });
    }
}
