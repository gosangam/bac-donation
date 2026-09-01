<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A guest pays before any account exists, so the owner is attached later
        // by LinkOrCreateDonorAccount. The donor details are already snapshotted
        // on the transaction, so the row stands on its own until then.
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->timestamp('linked_at')->nullable()->after('user_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->dropColumn('linked_at');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
