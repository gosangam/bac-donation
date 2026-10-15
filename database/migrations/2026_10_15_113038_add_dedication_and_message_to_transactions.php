<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A gift can be made on someone's behalf or in someone's memory. The
        // donor is still the payer and the receipt is still theirs; this only
        // records whom the gift honours, and the note they left with it.
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dedication_type', 20)->nullable()->after('purpose');
            $table->string('dedication_name')->nullable()->after('dedication_type');
            $table->text('donor_message')->nullable()->after('dedication_name');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['dedication_type', 'dedication_name', 'donor_message']);
        });
    }
};
