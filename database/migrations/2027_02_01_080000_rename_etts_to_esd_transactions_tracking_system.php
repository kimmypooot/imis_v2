<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ETTS is now the "ESD Transactions Tracking System" (it was "Examination
 * Transaction Tracking System"). Renames it in the portal directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')
            ->where('short_name', 'ETTS')
            ->update(['name' => 'ESD Transactions Tracking System', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('applications')
            ->where('short_name', 'ETTS')
            ->update(['name' => 'Examination Transaction Tracking System', 'updated_at' => now()]);
    }
};
