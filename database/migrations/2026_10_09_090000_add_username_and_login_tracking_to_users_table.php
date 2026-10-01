<?php

use App\Support\Usernames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sign-in moves from email to username, as in the legacy IMIS. Existing
     * accounts get a generated username (initials + surname) so nobody is
     * locked out; administrators can change it afterwards.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', Usernames::MAX_LENGTH)->nullable()->unique()->after('name');
            // Shown as "Last login" in the account menu: the sign-in
            // *before* the current one.
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->timestamp('previous_login_at')->nullable()->after('last_login_at');
        });

        DB::table('users')->whereNull('username')->orderBy('id')->each(function (object $user) {
            DB::table('users')->where('id', $user->id)->update([
                'username' => Usernames::generate($user->name, $user->id),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', Usernames::MAX_LENGTH)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'last_login_at', 'previous_login_at']);
        });
    }
};
