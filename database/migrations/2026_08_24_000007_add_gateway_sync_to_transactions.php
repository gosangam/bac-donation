<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // When we last asked the gateway what it thinks. Used to throttle:
            // a donor holding refresh must not turn into a burst of API calls.
            $table->timestamp('gateway_synced_at')->nullable()->after('gateway_payload');
            $table->string('gateway_sync_error')->nullable()->after('gateway_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['gateway_synced_at', 'gateway_sync_error']);
        });
    }
};
