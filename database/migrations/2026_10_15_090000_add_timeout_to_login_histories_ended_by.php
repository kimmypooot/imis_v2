<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sign-in can now also end by timing out (idle or absolute limit),
     * like the legacy "timeout" login status.
     */
    public function up(): void
    {
        Schema::table('login_histories', function (Blueprint $table) {
            $table->enum('ended_by', ['user', 'admin', 'timeout'])->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('login_histories')->where('ended_by', 'timeout')->update(['ended_by' => null]);

        Schema::table('login_histories', function (Blueprint $table) {
            $table->enum('ended_by', ['user', 'admin'])->nullable()->change();
        });
    }
};
