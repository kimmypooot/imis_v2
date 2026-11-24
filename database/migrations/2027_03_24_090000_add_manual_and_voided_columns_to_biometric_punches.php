<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DTR: HRD can add a missing punch and void a wrong one. A voided punch
 * stays on file (so a re-import of the same log does not bring it back) and
 * is simply not counted. A punch HRD adds has no import behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->unsignedBigInteger('biometric_import_id')->nullable()->change();
            $table->string('source', 10)->default('device')->after('punched_at');
            $table->string('reason', 255)->nullable()->after('source');
            $table->foreignId('created_by')->nullable()->after('reason')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('created_by');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['source', 'reason', 'voided_at', 'void_reason']);
        });
    }
};
