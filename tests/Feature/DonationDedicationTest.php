<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\ReceiptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DonationDedicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_TEST1'])]);
        config(['payments.razorpay.key_id' => 'rzp_test_x', 'payments.razorpay.key_secret' => 'secret']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'one_off', 'amount' => 50000, 'currency' => 'INR', 'gateway' => 'razorpay',
            'name' => 'Radha Sharma', 'email' => 'radha@example.com', 'phone' => '+91 98765 43210',
            'address_line1' => '21 Parikrama Marg', 'city' => 'Vrindavan',
            'postal_code' => '281121', 'country' => 'IN',
            'id_type' => 'pan', 'id_number' => 'ABCDE1234F',
        ], $overrides);
    }

    public function test_a_donation_can_be_made_in_someones_memory_with_a_message(): void
    {
        $this->post('/give/start', $this->payload([
            'dedication_type' => 'in_memory',
            'dedication_name' => 'Gopal Sharma',
            'donor_message' => 'For the dogs near Banke Bihari temple.',
        ]))->assertSessionHasNoErrors();

        $txn = Transaction::sole();
        $this->assertSame('in_memory', $txn->dedication_type);
        $this->assertSame('Gopal Sharma', $txn->dedication_name);
        $this->assertSame('In memory of Gopal Sharma', $txn->dedication);
        $this->assertSame('For the dogs near Banke Bihari temple.', $txn->donor_message);
    }

    public function test_a_dedication_needs_a_name(): void
    {
        $this->post('/give/start', $this->payload(['dedication_type' => 'on_behalf', 'dedication_name' => '']))
            ->assertSessionHasErrors(['dedication_name' => 'Enter the name of the person this donation is for.']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_an_unknown_dedication_type_is_rejected(): void
    {
        $this->post('/give/start', $this->payload(['dedication_type' => 'in_honour', 'dedication_name' => 'Gopal']))
            ->assertSessionHasErrors('dedication_type');
    }

    public function test_a_name_without_a_dedication_is_not_kept(): void
    {
        $this->post('/give/start', $this->payload(['dedication_type' => '', 'dedication_name' => 'Gopal']))
            ->assertSessionHasNoErrors();

        $txn = Transaction::sole();
        $this->assertNull($txn->dedication_type);
        $this->assertNull($txn->dedication_name);
    }

    public function test_the_checkout_form_offers_the_dedication_and_message(): void
    {
        $this->get('/give/details?kind=one_off&amount=500&currency=INR')
            ->assertOk()
            ->assertSee('On behalf of')
            ->assertSee('In memory of')
            ->assertSee('name="donor_message"', false);
    }

    public function test_the_receipt_prints_the_dedication_but_not_the_message(): void
    {
        $txn = Transaction::create([
            'gateway' => 'razorpay', 'type' => 'one_off', 'reference' => Transaction::newReference(),
            'amount' => 50000, 'currency' => 'INR', 'status' => 'paid', 'paid_at' => now(),
            'receipt_no' => 'BAC/2026-27/00099', 'purpose' => 'Dog rescue fund',
            'donor_name' => 'Radha Sharma', 'donor_email' => 'radha@example.com',
            'dedication_type' => 'on_behalf', 'dedication_name' => 'Meera Devi',
            'donor_message' => 'A private note for the team.',
        ]);

        $html = app(ReceiptRenderer::class)->html($txn);

        $this->assertStringContainsString('On behalf of', $html);
        $this->assertStringContainsString('Meera Devi', $html);
        $this->assertStringNotContainsString('A private note for the team.', $html);
    }
}
