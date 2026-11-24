<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: punches an upload left out because their ID is on no profile.
     */
    public function up(): void
    {
        Schema::table('biometric_imports', function (Blueprint $table) {
            $table->unsignedInteger('rows_ignored')->default(0)->after('rows_skipped');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_imports', function (Blueprint $table) {
            $table->dropColumn('rows_ignored');
        });
    }
};
