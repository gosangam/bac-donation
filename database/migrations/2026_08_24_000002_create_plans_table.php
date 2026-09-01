<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // Minor units (paise/cents), as every gateway expects. Never a float:
            // 0.1 + 0.2 problems have no place in a receipt.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('INR');

            $table->enum('interval', ['daily', 'weekly', 'monthly', 'yearly'])->default('monthly');
            $table->unsignedSmallInteger('interval_count')->default(1);

            // The same plan exists separately in each gateway, under its own id:
            // {"razorpay":"plan_...","stripe":"price_...","paypal":"P-..."}
            $table->json('gateway_plan_ids')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
