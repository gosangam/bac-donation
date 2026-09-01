<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_url_is_the_public_donation_page(): void
    {
        // No login wall: the front door is where you give.
        $this->get('/')
            ->assertOk()
            ->assertSee('Care for the animals of Braj')
            ->assertSee('no account needed', false);
    }

    public function test_the_login_page_is_reachable(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }
}
