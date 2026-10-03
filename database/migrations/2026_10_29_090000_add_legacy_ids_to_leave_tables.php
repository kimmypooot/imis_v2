<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-7: each record carried over from the legacy LMS keeps its legacy
     * id, so `imis:import-legacy-leave` can run again without duplicating.
     */
    private const TABLES = [
        'holidays', 'leave_ledger_entries', 'leave_applications', 'leave_designations',
        'leave_monetizations', 'fwa_arrangements', 'fwa_reports',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->unsignedBigInteger('legacy_id')->nullable()->after('id');
                $table->unique('legacy_id', "{$name}_legacy_id_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropUnique("{$name}_legacy_id_unique");
                $table->dropColumn('legacy_id');
            });
        }
    }
};
