<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The legacy subsystems identify people by users_cscro8.id, so each
     * account keeps its legacy id (set by the import or by LegacyBridge).
     * sso_tokens are the one-time sign-ins handed to those subsystems.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('legacy_id')->nullable()->unique()->after('id');
        });

        Schema::create('sso_tokens', function (Blueprint $table) {
            $table->id();
            // SHA-256 of the token; the token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            // First path segment of the launch URL (e.g. "otrs").
            $table->string('audience', 100)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
