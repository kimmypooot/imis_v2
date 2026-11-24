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
        Schema::table('leave_application_steps', function (Blueprint $table) {
            // Optional e-signature: set when the approver re-entered their password to sign.
            $table->timestamp('signed_at')->nullable()->after('acted_at');
            $table->string('signed_ip', 45)->nullable()->after('signed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_application_steps', function (Blueprint $table) {
            $table->dropColumn(['signed_at', 'signed_ip']);
        });
    }
};
