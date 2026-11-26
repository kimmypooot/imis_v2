<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each upload of a biometric log records the punches it skipped for being
 * dated before the DTR start date (Settings: dtr_punches_from), and how many
 * new punches it added per month ({"2026-11": 1204, "2026-10": 18}), so HRD
 * sees what came in and late punches for an earlier month stand out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_imports', function (Blueprint $table) {
            $table->unsignedInteger('rows_before_start')->default(0)->after('rows_ignored');
            $table->json('new_by_month')->nullable()->after('rows_before_start');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_imports', function (Blueprint $table) {
            $table->dropColumn(['rows_before_start', 'new_by_month']);
        });
    }
};
