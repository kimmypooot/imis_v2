<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar of Activities: an occurrence edited, moved or returned on its
 * own leaves its series (`series_id` is cleared) but remembers it in
 * `detached_from_series_id`, so a later "this and the following" edit
 * doesn't create the series' date again next to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('detached_from_series_id')->nullable()->after('series_id')
                ->constrained('activity_series')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('detached_from_series_id');
        });
    }
};
