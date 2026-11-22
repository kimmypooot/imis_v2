<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave on chosen dates that needn't run together (e.g. VL on 5 separate
 * days filed at once). When set, the leave covers exactly these dates;
 * date_from and date_to are the first and last of them. Null: every
 * day from date_from to date_to, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->json('dates')->nullable()->after('date_to');
        });
    }

    public function down(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->dropColumn('dates');
        });
    }
};
