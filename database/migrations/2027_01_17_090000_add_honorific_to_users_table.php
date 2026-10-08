<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A title such as "Atty." is how someone is addressed, not part of
     * their name, but the legacy IMIS only had a first-name field, so it
     * was typed there ("Atty. Juan"). It gets its own field, and existing
     * first names are split: honorific "Atty.", first name "Juan". The
     * display name keeps the title ("Atty. Juan D. Cruz"); the PDS and
     * other records that need the legal name no longer carry it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('honorific', 20)->nullable()->after('name');
        });

        DB::table('users')->whereNotNull('first_name')->orderBy('id')->each(function (object $user) {
            [$honorific, $firstName] = User::splitHonorific($user->first_name);

            if ($honorific !== null) {
                DB::table('users')->where('id', $user->id)->update(['honorific' => $honorific, 'first_name' => $firstName]);
            }
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNotNull('honorific')->orderBy('id')->each(function (object $user) {
            DB::table('users')->where('id', $user->id)->update(['first_name' => trim("{$user->honorific} {$user->first_name}")]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('honorific');
        });
    }
};
