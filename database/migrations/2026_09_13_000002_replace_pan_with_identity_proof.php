<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PAN was one of four identity proofs Form 10BD accepts, so it becomes a
        // type alongside Aadhaar, Voter ID and driving licence rather than a
        // field of its own. Long enough for a 16-character licence.
        Schema::table('users', function (Blueprint $table) {
            $table->string('id_type', 20)->nullable()->after('country');
            $table->string('id_number', 32)->nullable()->after('id_type');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('donor_id_type', 20)->nullable()->after('donor_address');
            $table->string('donor_id_number', 32)->nullable()->after('donor_id_type');
        });

        // Every PAN already on file is a PAN — carry it over before dropping the
        // column, or existing donors silently lose their identity proof and
        // their receipts stop matching what was filed.
        DB::table('users')->whereNotNull('pan')->where('pan', '!=', '')
            ->update(['id_type' => 'pan', 'id_number' => DB::raw('pan')]);

        DB::table('transactions')->whereNotNull('donor_pan')->where('donor_pan', '!=', '')
            ->update(['donor_id_type' => 'pan', 'donor_id_number' => DB::raw('donor_pan')]);

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pan'));
        Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn('donor_pan'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('pan', 10)->nullable());
        Schema::table('transactions', fn (Blueprint $table) => $table->string('donor_pan', 10)->nullable());

        // Only PANs can go back; the other three types have nowhere to live.
        DB::table('users')->where('id_type', 'pan')
            ->update(['pan' => DB::raw('id_number')]);
        DB::table('transactions')->where('donor_id_type', 'pan')
            ->update(['donor_pan' => DB::raw('donor_id_number')]);

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['id_type', 'id_number']));
        Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn(['donor_id_type', 'donor_id_number']));
    }
};
