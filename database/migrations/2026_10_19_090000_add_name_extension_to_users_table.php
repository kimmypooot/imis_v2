<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PDS item 2's NAME EXTENSION (JR., SR, III). It lives on the account
     * with the other name parts, so the display name ("Juan D. Cruz Jr.")
     * has one source.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name_extension', 10)->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name_extension');
        });
    }
};
