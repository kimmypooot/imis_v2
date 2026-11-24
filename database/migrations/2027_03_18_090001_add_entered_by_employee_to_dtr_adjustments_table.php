<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: an employee can enter their own in and out times for a day
     * (certified as true and correct). It is an adjustment like HRD's, kept
     * beside the raw punches, and marked so it is never mistaken for HRD's.
     */
    public function up(): void
    {
        Schema::table('dtr_adjustments', function (Blueprint $table) {
            $table->boolean('entered_by_employee')->default(false)->after('remark');
        });
    }

    public function down(): void
    {
        Schema::table('dtr_adjustments', function (Blueprint $table) {
            $table->dropColumn('entered_by_employee');
        });
    }
};
