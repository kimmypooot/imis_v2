<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The legacy IMIS account profile (users_cscro8: fname/mname/lname, sex,
     * birthday, position, fo_rsu, profile). `name` stays as the stored
     * display name ("Juan S. Dela Cruz"), kept in sync by the User model,
     * so every existing reader of `name` keeps working.
     *
     * Existing accounts are split best-effort — last word as the surname —
     * for administrators to correct; the legacy import fills these exactly.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 64)->nullable()->after('name');
            $table->string('middle_name', 64)->nullable()->after('first_name');
            $table->string('last_name', 64)->nullable()->after('middle_name');
            $table->enum('sex', ['male', 'female'])->nullable()->after('last_name');
            $table->date('birthday')->nullable()->after('sex');
            $table->string('position', 128)->nullable()->after('birthday');
            $table->foreignId('organizational_unit_id')->nullable()->after('position')
                ->constrained()->nullOnDelete();
            $table->string('profile_photo_path')->nullable()->after('organizational_unit_id');
        });

        DB::table('users')->orderBy('id')->each(function (object $user) {
            $words = preg_split('/\s+/', trim($user->name)) ?: [];
            $last = count($words) > 1 ? array_pop($words) : null;

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => implode(' ', $words) ?: $user->name,
                'last_name' => $last,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organizational_unit_id');
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'sex', 'birthday', 'position', 'profile_photo_path']);
        });
    }
};
