<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->string('gateway');
            $table->enum('type', ['one_off', 'subscription'])->default('one_off');

            // Set once the gateway confirms; the reference is created earlier so a
            // transaction row exists before the user ever reaches the checkout.
            $table->string('reference')->unique();
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('gateway_payment_id')->nullable()->index();

            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('INR');
            $table->string('status')->default('pending');   // pending|paid|failed|refunded
            $table->string('purpose')->nullable();
            $table->string('method')->nullable();            // card ••••4366, UPI, PayPal

            // Donor details are SNAPSHOTTED, not read from the user at print time:
            // a receipt must keep saying what it said when it was issued, even if
            // the donor later edits their profile.
            $table->string('donor_name');
            $table->string('donor_email');
            $table->string('donor_phone')->nullable();
            $table->text('donor_address')->nullable();
            $table->string('donor_pan', 10)->nullable();

            $table->string('receipt_no')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->json('gateway_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
