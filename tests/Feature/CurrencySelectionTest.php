<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencySelectionTest extends TestCase
{
    use RefreshDatabase;

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'slug' => 'monthly-500',
            'name' => 'Monthly Seva',
            'amount' => 50000,
            'currency' => 'INR',
            'amount_usd' => 600,
            'interval' => 'monthly',
            'interval_count' => 1,
            'gateway_plan_ids' => ['razorpay' => 'plan_x', 'paypal' => 'P-x'],
            'is_active' => true,
        ], $overrides));
    }

    public function test_defaults_to_domestic_currency_without_a_country_header(): void
    {
        $this->plan();

        $this->get('/')->assertOk()->assertSee('₹500.00')->assertDontSee('$6.00');
    }

    public function test_an_edge_country_header_selects_usd(): void
    {
        $this->plan();

        $this->withHeader('CF-IPCountry', 'US')
            ->get('/')->assertOk()->assertSee('$6.00')->assertDontSee('₹500.00');
    }

    public function test_an_indian_country_header_stays_on_rupees(): void
    {
        $this->plan();

        $this->withHeader('CF-IPCountry', 'IN')->get('/')->assertOk()->assertSee('₹500.00');
    }

    /** A VPN or an NRI on an Indian IP must be able to override the guess. */
    public function test_an_explicit_choice_beats_the_country_header(): void
    {
        $this->plan();

        $this->withSession([Currency::SESSION_KEY => 'INR'])
            ->withHeader('CF-IPCountry', 'US')
            ->get('/')->assertOk()->assertSee('₹500.00');
    }

    public function test_unplaceable_cloudflare_countries_are_ignored(): void
    {
        $this->plan();

        // XX = could not be placed, T1 = Tor. Neither locates the donor.
        foreach (['XX', 'T1'] as $code) {
            $this->withHeader('CF-IPCountry', $code)->get('/')->assertOk()->assertSee('₹500.00');
        }
    }

    public function test_the_switch_stores_the_choice_and_returns(): void
    {
        $this->plan();

        $this->from('/')->post('/currency', ['currency' => 'USD'])
            ->assertRedirect('/')
            ->assertSessionHas(Currency::SESSION_KEY, 'USD');

        $this->withSession([Currency::SESSION_KEY => 'USD'])->get('/')->assertSee('$6.00');
    }

    public function test_an_unsupported_currency_is_not_stored(): void
    {
        $this->from('/')->post('/currency', ['currency' => 'XYZ'])
            ->assertSessionMissing(Currency::SESSION_KEY);
    }

    public function test_a_plan_without_a_usd_price_is_hidden_from_foreign_donors(): void
    {
        $this->plan(['amount_usd' => null]);

        $this->withHeader('CF-IPCountry', 'US')->get('/')
            ->assertOk()->assertDontSee('Monthly Seva');
    }

    public function test_details_prices_a_plan_in_the_requested_currency(): void
    {
        $this->plan();

        $this->get('/give/details?kind=plan&plan=monthly-500&currency=USD')
            ->assertOk()
            ->assertSee('name="amount" value="600"', false)
            ->assertSee('name="currency" value="USD"', false);
    }

    public function test_details_redirects_when_the_plan_has_no_price_in_that_currency(): void
    {
        $this->plan(['amount_usd' => null]);

        $this->get('/give/details?kind=plan&plan=monthly-500&currency=USD')
            ->assertRedirect('/');
    }
}
