<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'a@x.test', 'password' => 'secret123']);
        // Not mass-assignable on purpose, so it cannot be granted by accident.
        $user->promoteToAdmin();

        return $user;
    }

    private function donor(): User
    {
        return User::create(['name' => 'Donor', 'email' => 'd@x.test', 'password' => 'secret123']);
    }

    #[DataProvider('donorRoutes')]
    public function test_admins_are_kept_out_of_the_giving_flow(string $uri): void
    {
        $this->actingAs($this->admin())->get($uri)->assertRedirect(route('admin.index'));
    }

    public static function donorRoutes(): array
    {
        return [['/dashboard'], ['/'], ['/subscriptions'], ['/transactions'], ['/profile']];
    }

    public function test_donors_can_still_reach_the_giving_flow(): void
    {
        $this->actingAs($this->donor())->get('/')->assertOk();
    }

    public function test_donors_cannot_see_the_admin_area(): void
    {
        $donor = $this->donor();

        $this->actingAs($donor)->get('/admin')->assertNotFound();
        $this->actingAs($donor)->get('/admin/users')->assertNotFound();
    }

    public function test_admins_are_redirected_off_the_public_homepage(): void
    {
        // Admins cannot donate, so the donation homepage is not for them.
        $this->actingAs($this->admin())->get('/')->assertRedirect(route('admin.index'));
    }

    public function test_donors_and_guests_both_get_the_donation_homepage(): void
    {
        $this->get('/')->assertOk();
        $this->actingAs($this->donor())->get('/')->assertOk()->assertSee('Make a donation');
    }

    public function test_admin_can_open_a_donor_record(): void
    {
        $donor = $this->donor();

        $this->actingAs($this->admin())
            ->get(route('admin.users.show', $donor))
            ->assertOk()
            ->assertSee($donor->name);
    }

    public function test_is_admin_cannot_be_granted_by_mass_assignment(): void
    {
        $donor = $this->donor();

        $donor->update(['is_admin' => true]);

        $this->assertFalse((bool) $donor->fresh()->is_admin);
    }

    public function test_gateway_plan_lookup_reports_an_unconfigured_gateway(): void
    {
        config(['payments.stripe.secret_key' => null]);

        $this->actingAs($this->admin())
            ->getJson(route('admin.gateway-plans', 'stripe'))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Stripe has no API keys configured.');
    }

    public function test_gateway_plan_lookup_rejects_an_unknown_gateway(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('admin.gateway-plans', 'bitcoin'))
            ->assertNotFound();
    }
}
