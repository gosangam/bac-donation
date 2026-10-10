<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DonorAccountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->promoteToAdmin();

        return $admin->fresh();
    }

    public function test_donors_cannot_create_accounts(): void
    {
        $donor = User::factory()->create();

        $this->actingAs($donor)->get(route('admin.users.create'))->assertNotFound();
        $this->actingAs($donor)
            ->post(route('admin.users.store'), ['name' => 'X', 'email' => 'x@example.com'])
            ->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_admin_creates_an_account_and_emails_a_set_password_link(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Radha Sharma',
            'email' => '  Radha@Example.com ',
            'phone' => '+91 98765 43210',
            'country' => 'IN',
            'send_invite' => '1',
            'is_admin' => '0',
        ]);

        $user = User::where('email', 'radha@example.com')->firstOrFail();
        $response->assertRedirect(route('admin.users.show', $user));
        $this->assertSame('Radha Sharma', $user->name);
        $this->assertSame('+91 98765 43210', $user->phone);
        $this->assertFalse($user->is_admin);
        Notification::assertSentTo($user, DonorAccountCreated::class);
    }

    public function test_admin_can_set_a_password_without_sending_an_invite(): void
    {
        Notification::fake();

        $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Gopal',
            'email' => 'gopal@example.com',
            'password' => 'chosen-password',
            'send_invite' => '0',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'gopal@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('chosen-password', $user->password));
        Notification::assertNothingSent();
    }

    public function test_an_email_already_in_use_is_rejected_whatever_its_case(): void
    {
        User::factory()->create(['email' => 'radha@example.com']);

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), ['name' => 'Radha', 'email' => 'RADHA@example.com'])
            ->assertSessionHasErrors(['email' => 'The email has already been taken.']);
    }

    public function test_an_identity_number_needs_its_type(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Radha', 'email' => 'radha@example.com', 'id_number' => 'ABCDE1234F',
            ])
            ->assertSessionHasErrors(['id_number' => 'Choose which identity proof this number belongs to.']);
    }

    public function test_editing_without_a_password_keeps_the_current_one(): void
    {
        $donor = User::factory()->create(['email' => 'radha@example.com', 'password' => 'original-password']);

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $donor), [
                'name' => 'Radha Devi Sharma',
                'email' => 'radha.sharma@example.com',
                'city' => 'Vrindavan',
                'password' => '',
            ])
            ->assertRedirect(route('admin.users.show', $donor));

        $donor->refresh();
        $this->assertSame('Radha Devi Sharma', $donor->name);
        $this->assertSame('radha.sharma@example.com', $donor->email);
        $this->assertSame('Vrindavan', $donor->city);
        $this->assertTrue(Hash::check('original-password', $donor->password));
    }

    public function test_admin_can_grant_and_revoke_admin_on_another_account(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $fields = ['name' => $other->name, 'email' => $other->email];

        $this->actingAs($admin)->put(route('admin.users.update', $other), $fields + ['is_admin' => '1']);
        $this->assertTrue($other->fresh()->is_admin);

        $this->actingAs($admin)->put(route('admin.users.update', $other), $fields + ['is_admin' => '0']);
        $this->assertFalse($other->fresh()->is_admin);
    }

    public function test_admin_cannot_revoke_their_own_admin_access(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'is_admin' => '0',
        ]);

        $this->assertTrue($admin->fresh()->is_admin);
    }
}
