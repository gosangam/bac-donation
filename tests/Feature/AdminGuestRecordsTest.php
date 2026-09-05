<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A guest donation has no owner until LinkOrCreateDonorAccount runs, which is
 * after the first payment clears. Every admin screen must survive rendering
 * those rows — reading ->user->name on one is what broke admin/subscriptions.
 */
class AdminGuestRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->promoteToAdmin();

        return $user->fresh();
    }

    private function seedUnlinkedGuestRecords(): void
    {
        $plan = Plan::create([
            'slug' => 'monthly-500', 'name' => 'Monthly Seva',
            'amount' => 50000, 'currency' => 'INR', 'amount_usd' => 600,
            'interval' => 'monthly', 'interval_count' => 1,
            'gateway_plan_ids' => ['razorpay' => 'plan_x'], 'is_active' => true,
        ]);

        Subscription::create([
            'user_id' => null,                 // the unlinked guest
            'plan_id' => $plan->id,
            'gateway' => 'razorpay',
            'gateway_subscription_id' => 'sub_guest',
            'status' => 'active',
        ]);

        Transaction::create([
            'user_id' => null,
            'gateway' => 'razorpay', 'type' => 'one_off',
            'reference' => Transaction::newReference(),
            'amount' => 50000, 'currency' => 'INR', 'status' => 'pending',
            'purpose' => 'Cow Feed Fund',
            'donor_name' => 'Radha Sharma', 'donor_email' => 'radha@example.com',
            'donor_phone' => '+91 98765 43210',
            'donor_address' => '21 Parikrama Marg, Vrindavan',
        ]);
    }

    public static function adminPages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'subscriptions' => ['/admin/subscriptions'],
            'transactions' => ['/admin/transactions'],
            'donors' => ['/admin/users'],
            'plans' => ['/admin/plans'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_admin_pages_render_with_unlinked_guest_records(string $path): void
    {
        $this->seedUnlinkedGuestRecords();

        $this->actingAs($this->admin())->get($path)->assertOk();
    }

    public function test_subscriptions_page_labels_the_unlinked_guest(): void
    {
        $this->seedUnlinkedGuestRecords();

        $this->actingAs($this->admin())->get('/admin/subscriptions')
            ->assertOk()
            ->assertSee('Unlinked guest');
    }
}
