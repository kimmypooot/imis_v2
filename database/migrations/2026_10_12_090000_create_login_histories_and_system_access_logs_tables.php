<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The legacy imis_history_login_logs and imis_history_access_logs.
     * Usernames / system names are snapshotted so history stays readable
     * after an account or system changes or is removed.
     */
    public function up(): void
    {
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username', 64);
            $table->string('session_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('logged_in_at')->index();
            $table->timestamp('logged_out_at')->nullable();
            // How the session ended: signed out, or ended by an administrator.
            $table->enum('ended_by', ['user', 'admin'])->nullable();

            $table->index(['user_id', 'logged_in_at']);
        });

        Schema::create('system_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username', 64);
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('application_name');
            // The role held at the moment of launch (null for an open system).
            $table->string('role_name', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('accessed_at')->index();

            $table->index(['user_id', 'accessed_at']);
            $table->index(['application_id', 'accessed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_access_logs');
        Schema::dropIfExists('login_histories');
    }
};
