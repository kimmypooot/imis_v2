<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stable machine key for a role (e.g. "esd_admin"), so a system that
     * maps IMIS roles onto its own (PROCTAD) keeps working when the display
     * name is renamed.
     */
    public function up(): void
    {
        Schema::table('application_roles', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('name');
            $table->unique(['application_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('application_roles', function (Blueprint $table) {
            $table->dropUnique(['application_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
