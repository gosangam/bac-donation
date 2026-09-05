<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatewayOfferingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.razorpay.key_id' => 'rzp_test_x',
            'payments.razorpay.key_secret' => 'secret',
            'payments.paypal.client_id' => 'client',
            'payments.paypal.secret' => 'secret',
            'payments.stripe.secret_key' => null,
        ]);
    }

    private function plan(array $ids, ?int $usd = 600): Plan
    {
        return Plan::create([
            'slug' => 'monthly-500', 'name' => 'Monthly Seva',
            'amount' => 50000, 'currency' => 'INR', 'amount_usd' => $usd,
            'interval' => 'monthly', 'interval_count' => 1,
            'gateway_plan_ids' => $ids, 'is_active' => true,
        ]);
    }

    public function test_rupee_plan_offers_razorpay_only(): void
    {
        $this->plan(['razorpay' => 'plan_x', 'paypal' => 'P-x']);

        $page = $this->get('/give/details?kind=plan&plan=monthly-500&currency=INR');

        $page->assertOk()
            ->assertSee('name="gateway" value="razorpay"', false)
            ->assertDontSee('name="gateway" value="paypal"', false);
    }

    public function test_dollar_plan_offers_paypal_only(): void
    {
        $this->plan(['razorpay' => 'plan_x', 'paypal' => 'P-x']);

        $page = $this->get('/give/details?kind=plan&plan=monthly-500&currency=USD');

        $page->assertOk()
            ->assertSee('name="gateway" value="paypal"', false)
            ->assertDontSee('name="gateway" value="razorpay"', false);
    }

    /**
     * A gateway the plan was never created in cannot take a subscription — it
     * would throw at the hand-off, after the donor filled the whole form.
     */
    public function test_a_gateway_without_a_mapped_plan_id_is_not_offered(): void
    {
        $this->plan(['razorpay' => 'plan_x']);   // no PayPal id

        $this->get('/give/details?kind=plan&plan=monthly-500&currency=USD')
            ->assertOk()
            ->assertDontSee('name="gateway" value="paypal"', false)
            ->assertSee("can't be taken in USD yet", false);
    }

    /** One-off donations have no plan, so only currency and keys matter. */
    public function test_one_off_is_unaffected_by_plan_mappings(): void
    {
        $this->get('/give/details?kind=one_off&amount=10&currency=USD')
            ->assertOk()->assertSee('name="gateway" value="paypal"', false);
    }

    public function test_posting_an_unmapped_gateway_is_rejected(): void
    {
        $this->plan(['razorpay' => 'plan_x']);   // no PayPal id

        $this->post('/give/start', [
            'kind' => 'plan', 'plan' => 'monthly-500',
            'amount' => 600, 'currency' => 'USD', 'gateway' => 'paypal',
            'name' => 'Radha Sharma', 'email' => 'radha@example.com',
            'phone' => '+91 98765 43210', 'address_line1' => '21 Parikrama Marg',
            'city' => 'Vrindavan', 'postal_code' => '281121', 'country' => 'IN',
        ])->assertSessionHasErrors('gateway');
    }
}
