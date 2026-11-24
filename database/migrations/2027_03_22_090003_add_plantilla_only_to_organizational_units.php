<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An office that exists only so plantilla items can name it as their place
 * of assignment (the CSC Field Office - Leyte over Leyte I and II). It stays
 * out of every list people pick a working office from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizational_units', function (Blueprint $table) {
            $table->boolean('plantilla_only')->default(false)->after('status');
        });

        DB::table('organizational_units')->where('code', 'LFO')->update(['plantilla_only' => true]);
    }

    public function down(): void
    {
        Schema::table('organizational_units', function (Blueprint $table) {
            $table->dropColumn('plantilla_only');
        });
    }
};
