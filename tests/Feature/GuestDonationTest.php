<?php

namespace Tests\Feature;

use App\Jobs\LinkOrCreateDonorAccount;
use App\Jobs\SendDonationReceipt;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\DonorAccountCreated;
use App\Payments\WebhookEvent;
use App\Services\PaymentRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuestDonationTest extends TestCase
{
    use RefreshDatabase;

    private function guestTransaction(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => null,
            'gateway' => 'razorpay', 'type' => 'one_off',
            'reference' => Transaction::newReference(),
            'amount' => 50000, 'currency' => 'INR', 'status' => 'pending',
            'purpose' => 'Cow Feed Fund',
            'donor_name' => 'Radha Sharma', 'donor_email' => 'radha@example.com',
            'donor_phone' => '+91 98765 43210', 'donor_address' => '21 Parikrama Marg, Vrindavan',
        ], $overrides));
    }

    private function pay(Transaction $txn, string $paymentId = 'pay_GUEST01'): void
    {
        app(PaymentRecorder::class)->recordSuccess('razorpay', new WebhookEvent(
            type: 'payment_succeeded',
            reference: $txn->reference,
            paymentId: $paymentId,
            amount: $txn->amount,
            currency: $txn->currency,
        ));
    }

    public function test_a_guest_can_reach_the_giving_pages(): void
    {
        $this->get('/')->assertOk();
        $this->get('/give')->assertRedirect(route('checkout.choose'));
        $this->get('/give/details?kind=one_off&amount=500&currency=INR')->assertOk();
    }

    public function test_no_page_in_the_giving_flow_requires_a_login(): void
    {
        foreach (['/', '/give/details?kind=one_off&amount=500&currency=INR', '/login', '/register',
            '/forgot-password'] as $uri) {
            $response = $this->get($uri);

            $this->assertNotSame(302, $response->status(),
                "{$uri} redirected a guest; giving must not require an account.");
        }
    }

    public function test_paying_as_a_guest_queues_account_linking(): void
    {
        Queue::fake();
        $txn = $this->guestTransaction();

        $this->pay($txn);

        Queue::assertPushed(LinkOrCreateDonorAccount::class);
    }

    public function test_a_signed_in_donors_payment_does_not_queue_linking(): void
    {
        Queue::fake();
        $user = User::create(['name' => 'D', 'email' => 'd@x.test', 'password' => 'secret123']);
        $txn = $this->guestTransaction(['user_id' => $user->id]);

        $this->pay($txn);

        Queue::assertNotPushed(LinkOrCreateDonorAccount::class);
    }

    public function test_it_creates_an_account_and_sends_a_set_password_email(): void
    {
        Notification::fake();
        $txn = $this->guestTransaction();
        $txn->update(['status' => 'paid', 'paid_at' => now()]);

        (new LinkOrCreateDonorAccount($txn))->handle();

        $user = User::whereRaw('lower(email) = ?', ['radha@example.com'])->first();
        $this->assertNotNull($user);
        $this->assertSame('Radha Sharma', $user->name);
        $this->assertSame($user->id, $txn->fresh()->user_id);
        $this->assertNotNull($txn->fresh()->linked_at);
        Notification::assertSentTo($user, DonorAccountCreated::class);
    }

    public function test_it_links_to_an_existing_account_without_emailing_a_reset(): void
    {
        Notification::fake();
        $existing = User::create([
            'name' => 'Radha Sharma', 'email' => 'Radha@Example.com', 'password' => 'secret123',
        ]);
        $txn = $this->guestTransaction(['status' => 'paid', 'paid_at' => now()]);

        (new LinkOrCreateDonorAccount($txn))->handle();

        $this->assertSame($existing->id, $txn->fresh()->user_id);
        $this->assertSame(1, User::count());
        // An unrequested reset mail would also confirm the account exists.
        Notification::assertNothingSent();
    }

    public function test_linking_never_overwrites_an_existing_profile(): void
    {
        Notification::fake();
        $existing = User::create([
            'name' => 'Radha S', 'email' => 'radha@example.com', 'password' => 'secret123',
            'phone' => '+91 11111 11111',
        ]);
        $existing->forceFill(['city' => 'Delhi'])->save();

        (new LinkOrCreateDonorAccount(
            $this->guestTransaction(['status' => 'paid', 'paid_at' => now(), 'donor_name' => 'HACKED'])
        ))->handle();

        $existing->refresh();
        $this->assertSame('Radha S', $existing->name);
        $this->assertSame('+91 11111 11111', $existing->phone);
        $this->assertSame('Delhi', $existing->city);
    }

    public function test_earlier_guest_donations_from_the_same_email_are_linked_too(): void
    {
        Notification::fake();
        $older = $this->guestTransaction(['status' => 'paid', 'paid_at' => now()->subDay()]);
        $newer = $this->guestTransaction(['status' => 'paid', 'paid_at' => now()]);

        (new LinkOrCreateDonorAccount($newer))->handle();

        $user = User::whereRaw('lower(email) = ?', ['radha@example.com'])->first();
        $this->assertSame($user->id, $older->fresh()->user_id);
        $this->assertSame($user->id, $newer->fresh()->user_id);
    }

    public function test_it_does_nothing_for_an_unpaid_transaction(): void
    {
        Notification::fake();

        (new LinkOrCreateDonorAccount($this->guestTransaction()))->handle();

        $this->assertSame(0, User::count());
        Notification::assertNothingSent();
    }

    public function test_the_new_account_cannot_be_signed_into_without_the_reset_link(): void
    {
        Notification::fake();
        $txn = $this->guestTransaction(['status' => 'paid', 'paid_at' => now()]);

        (new LinkOrCreateDonorAccount($txn))->handle();

        // No password was ever chosen or emailed, so nothing guessable works.
        foreach (['', 'password', 'radha@example.com', 'Radha Sharma'] as $guess) {
            $this->post('/login', ['email' => 'radha@example.com', 'password' => $guess])
                ->assertRedirect();
            $this->assertGuest();
        }
    }

    public function test_a_guest_cannot_read_a_transaction_they_did_not_pay_for(): void
    {
        $someoneElse = $this->guestTransaction(['status' => 'paid', 'paid_at' => now()]);

        $this->get(route('transactions.show', $someoneElse))->assertForbidden();
        $this->get(route('transactions.receipt', $someoneElse))->assertForbidden();
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        User::create(['name' => 'D', 'email' => 'known@x.test', 'password' => 'secret123']);

        $known = $this->post('/forgot-password', ['email' => 'known@x.test']);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@x.test']);

        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status')
        );
    }
}
