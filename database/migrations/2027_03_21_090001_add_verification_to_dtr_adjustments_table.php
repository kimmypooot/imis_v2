<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: a day the employee entered themselves is verified by their
     * supervisor or office head (CS Form 48, "Verified as to the prescribed
     * office hours"), or by HRD. Changing the entry clears the verification.
     */
    public function up(): void
    {
        Schema::table('dtr_adjustments', function (Blueprint $table) {
            $table->foreignId('verified_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('dtr_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
        });
    }
};
