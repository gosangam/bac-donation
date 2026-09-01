<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_url_sends_a_guest_to_the_login_page(): void
    {
        // Replaces Laravel's stock "/ returns 200" assertion: this app has no
        // public landing page, so the root redirects.
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_login_page_is_reachable(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }
}
