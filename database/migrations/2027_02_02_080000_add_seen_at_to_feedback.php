<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an administrator first opened a piece of feedback. Opening it clears
 * the sidebar badge without changing the status the sender sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->timestamp('seen_at')->nullable()->after('status_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropColumn('seen_at');
        });
    }
};
