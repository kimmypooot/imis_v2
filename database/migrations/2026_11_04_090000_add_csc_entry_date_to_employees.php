<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When someone joined the CSC, for those who came from another agency (the
 * original appointment date is when their government service began).
 * Loyalty awards count service in the CSC only. Empty: the same as the
 * original appointment date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('csc_entry_date')->nullable()->after('original_appointment_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('csc_entry_date');
        });
    }
};
