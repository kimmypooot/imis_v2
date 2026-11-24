<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            // Optional e-signature of the applicant (CS Form No. 6, item 6.C), set when they re-entered their password.
            $table->timestamp('signed_at')->nullable()->after('submitted_at');
            $table->string('signed_ip', 45)->nullable()->after('signed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->dropColumn(['signed_at', 'signed_ip']);
        });
    }
};
