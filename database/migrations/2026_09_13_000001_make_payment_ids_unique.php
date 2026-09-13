<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two deliveries of the same webhook can be handled concurrently: both
        // look for a row, both find none, and both write one. Everything else
        // about idempotency is advisory — this makes it structural.
        //
        // NULLs do not collide in a unique index on either SQLite or MySQL, so
        // the many pending rows with no payment id yet are unaffected.
        $duplicates = DB::table('transactions')
            ->select('gateway', 'gateway_payment_id')
            ->whereNotNull('gateway_payment_id')
            ->groupBy('gateway', 'gateway_payment_id')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $listed = $duplicates
                ->map(fn ($row) => "{$row->gateway}:{$row->gateway_payment_id}")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot add the unique index: these payments are already recorded twice — '.
                $listed.'. Merge or delete the duplicates, then migrate again.'
            );
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(['gateway', 'gateway_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['gateway', 'gateway_payment_id']);
        });
    }
};
