<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->string('gateway');                       // razorpay | stripe | paypal
            $table->string('gateway_subscription_id')->nullable()->index();

            // Gateway vocabularies differ (active/authenticated/halted vs
            // active/past_due vs ACTIVE/SUSPENDED); normalised on the way in.
            $table->string('status')->default('pending');
            $table->string('gateway_status')->nullable();

            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
