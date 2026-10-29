<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A committee member's effective dates are optional, like the committee's own.
     */
    public function up(): void
    {
        Schema::table('committee_members', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('committee_members', function (Blueprint $table) {
            $table->date('effective_from')->nullable(false)->change();
        });
    }
};
