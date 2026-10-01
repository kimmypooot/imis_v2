<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('under_maintenance')->default(false)->after('requires_access');
            $table->string('maintenance_message', 500)->nullable()->after('under_maintenance');
            $table->timestamp('maintenance_started_at')->nullable()->after('maintenance_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['under_maintenance', 'maintenance_message', 'maintenance_started_at']);
        });
    }
};
