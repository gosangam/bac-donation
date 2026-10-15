<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\ReceiptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AdminTransactionDetailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->promoteToAdmin();

        return $admin->fresh();
    }

    private function payment(array $attributes = []): Transaction
    {
        return Transaction::create($attributes + [
            'gateway' => 'razorpay', 'type' => 'one_off', 'reference' => 'BAC-TESTREF00001',
            'amount' => 50000, 'currency' => 'INR', 'status' => 'paid', 'paid_at' => now(),
            'receipt_no' => 'BAC/2026-27/00042', 'purpose' => 'Dog rescue fund',
            'gateway_payment_id' => 'pay_ABC123', 'gateway_order_id' => 'order_XYZ789',
            'donor_name' => 'Radha Sharma', 'donor_email' => 'radha@example.com',
            'donor_id_type' => 'aadhaar', 'donor_id_number' => '458119548385',
            'dedication_type' => 'in_memory', 'dedication_name' => 'Gopal Sharma',
            'donor_message' => 'For the monkeys at Nidhivan.',
        ]);
    }

    public function test_donors_cannot_open_the_admin_transaction_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.transactions.show', $this->payment()))
            ->assertNotFound();
    }

    public function test_the_page_shows_the_payment_in_full_with_the_id_masked(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.transactions.show', $this->payment()))
            ->assertOk()
            ->assertSee('BAC/2026-27/00042')
            ->assertSee('pay_ABC123')
            ->assertSee('order_XYZ789')
            ->assertSee('In memory of Gopal Sharma')
            ->assertSee('For the monkeys at Nidhivan.')
            ->assertSee('XXXX XXXX 8385')
            ->assertDontSee('458119548385')
            ->assertDontSee('4581 1954 8385');
    }

    public function test_rows_in_the_transaction_list_lead_to_the_page(): void
    {
        $txn = $this->payment();

        $this->actingAs($this->admin())
            ->get(route('admin.transactions'))
            ->assertSee('data-href="'.route('admin.transactions.show', $txn).'"', false);
    }

    public function test_an_admin_can_reveal_the_full_id_and_it_is_logged(): void
    {
        Log::spy();
        $txn = $this->payment();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(route('admin.transactions.reveal-id', $txn))
            ->assertOk()
            ->assertExactJson(['number' => '4581 1954 8385']);

        Log::shouldHaveReceived('notice')->once()->with(
            'Donor identity number revealed on screen',
            Mockery::subset(['admin_id' => $admin->id, 'transaction_id' => $txn->id])
        );
    }

    public function test_revealing_needs_an_id_on_file(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.transactions.reveal-id', $this->payment(['donor_id_number' => null])))
            ->assertNotFound();
    }

    public function test_donors_cannot_reveal_an_id(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.transactions.reveal-id', $this->payment()))
            ->assertNotFound();
    }

    public function test_the_receipt_downloads_with_the_id_masked_by_default(): void
    {
        $txn = $this->payment();
        $this->mock(ReceiptRenderer::class, function ($renderer) use ($txn) {
            $renderer->shouldReceive('pdf')->once()->with(Mockery::on(fn ($t) => $t->is($txn)), 'masked')->andReturn('%PDF-');
            $renderer->shouldReceive('filename')->andReturn('Receipt.pdf');
        });

        $this->actingAs($this->admin())->get(route('admin.receipt', $txn))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_full_id_receipt_is_logged(): void
    {
        Log::spy();
        $txn = $this->payment();
        $this->mock(ReceiptRenderer::class, function ($renderer) use ($txn) {
            $renderer->shouldReceive('pdf')->once()->with(Mockery::on(fn ($t) => $t->is($txn)), 'full')->andReturn('%PDF-');
            $renderer->shouldReceive('filename')->andReturn('Receipt.pdf');
        });

        $this->actingAs($this->admin())->get(route('admin.receipt', [$txn, 'identity' => 'full']))->assertOk();

        Log::shouldHaveReceived('notice')->once()
            ->with('Donor identity number printed on a receipt', Mockery::type('array'));
    }

    public function test_the_receipt_prints_the_id_as_asked(): void
    {
        $txn = $this->payment(['donor_id_type' => 'pan', 'donor_id_number' => 'ABCDE1234F']);
        $renderer = app(ReceiptRenderer::class);

        $this->assertStringContainsString('XXXXXX234F', $renderer->html($txn, identity: 'masked'));
        $this->assertStringNotContainsString('ABCDE1234F', $renderer->html($txn, identity: 'masked'));
        $this->assertStringContainsString('ABCDE1234F', $renderer->html($txn, identity: 'full'));
    }
}
