<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\IdentityProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentityProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // No test here should reach a gateway; a real call would be slow and
        // would make the suite depend on someone else's uptime.
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER1', 'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve']],
            ]),
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_TEST1']),
        ]);

        config([
            'payments.razorpay.key_id' => 'rzp_test_x',
            'payments.razorpay.key_secret' => 'secret',
            'payments.paypal.client_id' => 'client',
            'payments.paypal.secret' => 'secret',
        ]);
    }

    // 4581 1954 8385 is Verhoeff-valid; the same digits ending 0 are a typo.
    public static function validNumbers(): array
    {
        return [
            'aadhaar' => ['aadhaar', '458119548385'],
            'aadhaar with spaces' => ['aadhaar', '4581 1954 8385'],
            'pan' => ['pan', 'ABCDE1234F'],
            'pan lowercase' => ['pan', 'abcde1234f'],
            'voter id' => ['voter_id', 'ABC1234567'],
            'licence' => ['driving_licence', 'DL0420110149646'],
            'licence with hyphen' => ['driving_licence', 'DL-0420110149646'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_valid_numbers_are_accepted(string $type, string $number): void
    {
        $this->assertTrue(IdentityProof::isValid($type, $number));
    }

    public static function invalidNumbers(): array
    {
        return [
            'aadhaar failing its checksum' => ['aadhaar', '458119548380'],
            'aadhaar starting with 1' => ['aadhaar', '158119548385'],
            'aadhaar too short' => ['aadhaar', '45811954838'],
            'pan with digits first' => ['pan', '12345ABCDF'],
            'pan too short' => ['pan', 'ABCDE1234'],
            'voter id all digits' => ['voter_id', '1234567890'],
            'licence too short' => ['driving_licence', 'DL04201'],
            'empty' => ['pan', ''],
            'unknown type' => ['passport', 'A1234567'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numbers_are_rejected(string $type, string $number): void
    {
        $this->assertFalse(IdentityProof::isValid($type, $number));
    }

    /** UIDAI's position is that a full Aadhaar must not be printed. */
    public function test_only_aadhaar_is_masked_for_display(): void
    {
        $this->assertSame('XXXX XXXX 8385', IdentityProof::forDisplay('aadhaar', '4581 1954 8385'));
        $this->assertSame('ABCDE1234F', IdentityProof::forDisplay('pan', 'ABCDE1234F'));
        $this->assertSame('Aadhaar No. XXXX XXXX 8385', IdentityProof::describe('aadhaar', '458119548385'));
        $this->assertNull(IdentityProof::describe('pan', null));
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

    public function test_an_inr_donation_requires_an_identity_proof(): void
    {
        $this->post('/give/start', $this->payload(['id_type' => '', 'id_number' => '']))
            ->assertSessionHasErrors(['id_type', 'id_number']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_foreign_currency_donation_does_not(): void
    {
        $this->post('/give/start', $this->payload([
            'currency' => 'USD', 'gateway' => 'paypal', 'amount' => 2500,
            'id_type' => '', 'id_number' => '',
        ]))->assertSessionHasNoErrors(['id_type', 'id_number']);
    }

    public function test_the_number_is_validated_against_the_chosen_type(): void
    {
        // A perfectly good PAN, submitted as an Aadhaar.
        $this->post('/give/start', $this->payload(['id_type' => 'aadhaar', 'id_number' => 'ABCDE1234F']))
            ->assertSessionHasErrors('id_number');
    }

    public function test_an_aadhaar_typo_is_caught_by_the_checksum(): void
    {
        $this->post('/give/start', $this->payload(['id_type' => 'aadhaar', 'id_number' => '4581 1954 8380']))
            ->assertSessionHasErrors('id_number');
    }

    public function test_the_number_is_stored_without_the_separators_donors_type(): void
    {
        $this->post('/give/start', $this->payload([
            'id_type' => 'aadhaar', 'id_number' => '4581 1954 8385',
        ]))->assertSessionHasNoErrors();

        $txn = Transaction::sole();
        $this->assertSame('aadhaar', $txn->donor_id_type);
        $this->assertSame('458119548385', $txn->donor_id_number);
    }

    public function test_a_type_with_no_number_records_neither(): void
    {
        $this->post('/give/start', $this->payload([
            'currency' => 'USD', 'gateway' => 'paypal', 'amount' => 2500,
            'id_type' => 'pan', 'id_number' => '',
        ]))->assertSessionHasNoErrors();

        $txn = Transaction::sole();
        $this->assertNull($txn->donor_id_type);
        $this->assertNull($txn->donor_id_number);
    }

    public function test_a_signed_in_donors_profile_keeps_the_proof(): void
    {
        $user = User::create([
            'name' => 'Radha Sharma', 'email' => 'radha@example.com', 'password' => 'secret123',
        ]);

        $this->actingAs($user)
            ->post('/give/start', $this->payload(['id_type' => 'voter_id', 'id_number' => 'ABC1234567']))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('voter_id', $user->id_type);
        $this->assertSame('ABC1234567', $user->id_number);
    }

    public function test_the_checkout_form_offers_all_four_types(): void
    {
        $page = $this->get('/give/details?kind=one_off&amount=500&currency=INR')->assertOk();

        foreach (IdentityProof::TYPES as $value => $label) {
            $page->assertSee('value="'.$value.'"', false);
            $page->assertSee($label);
        }

        $page->assertSee('Required for donations in rupees', false);
    }

    public function test_the_form_marks_the_proof_optional_for_foreign_currency(): void
    {
        $this->get('/give/details?kind=one_off&amount=25&currency=USD')
            ->assertOk()
            ->assertSee('Only needed if you intend to claim', false);
    }

    public function test_the_receipt_masks_aadhaar(): void
    {
        $txn = Transaction::create([
            'gateway' => 'razorpay', 'type' => 'one_off', 'reference' => Transaction::newReference(),
            'amount' => 50000, 'currency' => 'INR', 'status' => 'paid', 'paid_at' => now(),
            'receipt_no' => 'BAC/2026-27/00099', 'purpose' => 'Cow Feed Fund',
            'donor_name' => 'Radha Sharma', 'donor_email' => 'radha@example.com',
            'donor_id_type' => 'aadhaar', 'donor_id_number' => '458119548385',
        ]);

        $html = app(\App\Services\ReceiptRenderer::class)->html($txn);

        $this->assertStringContainsString('Aadhaar No.', $html);
        $this->assertStringContainsString('XXXX XXXX 8385', $html);
        $this->assertStringNotContainsString('458119548385', $html);
    }
}
