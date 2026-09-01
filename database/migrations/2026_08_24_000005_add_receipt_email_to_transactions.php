<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Set once the receipt has actually been emailed, so a webhook retry
            // or a manual resend cannot send a donor the same receipt twice.
            $table->timestamp('receipt_emailed_at')->nullable()->after('receipt_no');
            $table->string('receipt_email_error')->nullable()->after('receipt_emailed_at');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['receipt_emailed_at', 'receipt_email_error']);
        });
    }
};
