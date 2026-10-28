<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single sign-out: a token remembers the IMIS session that issued it,
     * and redeeming it opens an sso_session, so signing out of IMIS can
     * tell each Laravel system to end the session it started.
     */
    public function up(): void
    {
        Schema::table('sso_tokens', function (Blueprint $table) {
            $table->string('imis_session_id', 100)->nullable()->after('audience');
        });

        Schema::create('sso_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('imis_session_id', 100)->index();
            // Random reference the system stores with its own session; the
            // only credential for the logout call (encrypted with APP_KEY).
            $table->text('sid');
            $table->string('logout_url', 500);
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_sessions');

        Schema::table('sso_tokens', function (Blueprint $table) {
            $table->dropColumn('imis_session_id');
        });
    }
};
