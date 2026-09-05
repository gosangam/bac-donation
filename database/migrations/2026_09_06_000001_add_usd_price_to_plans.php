<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Foreign donors are billed in dollars: PayPal does not settle INR
            // for an Indian merchant, so a rupee-only plan can never be offered
            // to them. Cents, like `amount` is paise. Null means this plan is
            // domestic-only and simply will not appear when USD is selected.
            $table->unsignedBigInteger('amount_usd')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('amount_usd');
        });
    }
};
