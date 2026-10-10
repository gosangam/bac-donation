<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDonationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->promoteToAdmin();

        return $admin->fresh();
    }

    private function subscription(?User $owner, string $gatewayId = 'sub_123'): Subscription
    {
        $plan = Plan::create([
            'slug' => 'monthly-500', 'name' => 'Monthly Seva',
            'amount' => 50000, 'currency' => 'INR',
            'interval' => 'monthly', 'interval_count' => 1, 'is_active' => true,
        ]);

        return Subscription::create([
            'user_id' => $owner?->id,
            'plan_id' => $plan->id,
            'gateway' => 'razorpay',
            'gateway_subscription_id' => $gatewayId,
            'status' => 'active',
        ]);
    }

    private function payment(?User $owner, ?Subscription $subscription = null, array $attributes = []): Transaction
    {
        return Transaction::create($attributes + [
            'user_id' => $owner?->id,
            'subscription_id' => $subscription?->id,
            'gateway' => 'razorpay',
            'type' => $subscription ? 'subscription' : 'one_off',
            'reference' => Transaction::newReference(),
            'amount' => 50000, 'currency' => 'INR', 'status' => 'paid',
            'purpose' => 'Donation',
            'donor_name' => 'Radha Sharma', 'donor_email' => 'radha.work@example.com',
        ]);
    }

    public function test_donors_cannot_reassign_donations(): void
    {
        $donor = User::factory()->create();
        $payment = $this->payment(null);

        $this->actingAs($donor)
            ->put(route('admin.transactions.owner', $payment), ['email' => $donor->email])
            ->assertNotFound();

        $this->assertNull($payment->fresh()->user_id);
    }

    public function test_reassigning_a_subscription_moves_its_payments_with_it(): void
    {
        $previous = User::factory()->create();
        $donor = User::factory()->create(['email' => 'radha@example.com']);
        $subscription = $this->subscription($previous);
        $renewal = $this->payment($previous, $subscription);
        $unrelated = $this->payment($previous);

        $this->actingAs($this->admin())
            ->put(route('admin.subscriptions.owner', $subscription), ['email' => 'Radha@Example.com'])
            ->assertSessionHas('status', 'Subscription linked to radha@example.com.');

        $this->assertSame($donor->id, $subscription->fresh()->user_id);
        $this->assertSame($donor->id, $renewal->fresh()->user_id);
        $this->assertSame($previous->id, $unrelated->fresh()->user_id);
    }

    public function test_a_blank_email_unlinks_a_subscription(): void
    {
        $subscription = $this->subscription(User::factory()->create());

        $this->actingAs($this->admin())
            ->put(route('admin.subscriptions.owner', $subscription), ['email' => ''])
            ->assertSessionHas('status', 'Subscription unlinked.');

        $this->assertNull($subscription->fresh()->user_id);
    }

    public function test_a_donor_can_hold_several_subscriptions(): void
    {
        $donor = User::factory()->create();
        $first = $this->subscription(null, 'sub_first');
        $second = Subscription::create([
            'plan_id' => $first->plan_id, 'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_second', 'status' => 'active',
        ]);
        $admin = $this->admin();

        foreach (['sub_first', 'sub_second'] as $gatewayId) {
            $this->actingAs($admin)
                ->post(route('admin.users.subscriptions.attach', $donor), ['subscription' => $gatewayId])
                ->assertSessionHasNoErrors();
        }

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $donor->subscriptions()->pluck('id')->all());
    }

    public function test_attaching_an_unknown_subscription_id_is_rejected(): void
    {
        $donor = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.users.subscriptions.attach', $donor), ['subscription' => 'sub_missing'])
            ->assertSessionHasErrors(['subscription' => 'No subscription has that gateway id.']);
    }

    public function test_reassigning_a_payment_keeps_the_receipt_details(): void
    {
        $donor = User::factory()->create(['email' => 'radha@example.com']);
        $payment = $this->payment(null);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.owner', $payment), ['email' => 'radha@example.com'])
            ->assertSessionHas('status', 'Transaction linked to radha@example.com.');

        $payment->refresh();
        $this->assertSame($donor->id, $payment->user_id);
        $this->assertNotNull($payment->linked_at);
        $this->assertSame('radha.work@example.com', $payment->donor_email);
    }

    public function test_attaching_a_payment_by_receipt_number(): void
    {
        $donor = User::factory()->create();
        $payment = $this->payment(null, null, ['receipt_no' => 'BAC/2026-27/00042']);

        $this->actingAs($this->admin())
            ->post(route('admin.users.transactions.attach', $donor), ['transaction' => 'BAC/2026-27/00042'])
            ->assertSessionHas('status', 'Transaction linked.');

        $this->assertSame($donor->id, $payment->fresh()->user_id);
    }

    public function test_an_email_with_no_account_is_rejected(): void
    {
        $payment = $this->payment(null);

        $this->actingAs($this->admin())
            ->put(route('admin.transactions.owner', $payment), ['email' => 'nobody@example.com'])
            ->assertSessionHasErrors(['email' => 'No account uses that email. Create it under Donors first.']);

        $this->assertNull($payment->fresh()->user_id);
    }

    public function test_an_admin_can_own_donations_like_any_donor(): void
    {
        $admin = $this->admin();
        $payment = $this->payment(null);

        $this->actingAs($admin)
            ->put(route('admin.transactions.owner', $payment), ['email' => $admin->email])
            ->assertSessionHasNoErrors();

        $this->assertSame($admin->id, $payment->fresh()->user_id);
    }

    public function test_admin_pages_render_the_new_forms(): void
    {
        $donor = User::factory()->create();
        $this->payment($donor, $this->subscription($donor));
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.subscriptions'))->assertOk()->assertSee('aria-label="Change donor"', false);
        $this->actingAs($admin)->get(route('admin.transactions'))->assertOk()->assertSee('aria-label="Change donor"', false);
        $this->actingAs($admin)->get(route('admin.users.show', $donor))->assertOk()->assertSee('Edit account');
        $this->actingAs($admin)->get(route('admin.users.edit', $donor))->assertOk()->assertSee($donor->email);
        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()->assertSee('Create account');
    }
}
