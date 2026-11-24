<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A person's saved signature image, and the copies kept with what they signed.
        Schema::table('users', function (Blueprint $table) {
            $table->string('signature_path')->nullable();
        });
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('signed_ip');
        });
        Schema::table('leave_application_steps', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('signed_ip');
        });
    }

    public function down(): void
    {
        Schema::table('leave_application_steps', fn (Blueprint $table) => $table->dropColumn('signature_path'));
        Schema::table('leave_applications', fn (Blueprint $table) => $table->dropColumn('signature_path'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('signature_path'));
    }
};
