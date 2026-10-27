<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-4: an appointment may carry its own monthly salary, replacing the
     * salary schedule's amount for that period (blank follows the schedule).
     */
    public function up(): void
    {
        Schema::table('plantilla_appointments', function (Blueprint $table) {
            $table->decimal('salary_override', 12, 2)->nullable()->after('step');
        });
    }

    public function down(): void
    {
        Schema::table('plantilla_appointments', fn (Blueprint $table) => $table->dropColumn('salary_override'));
    }
};
