<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: HRD's corrections for one day. `credit` credits a full day
     * (an event, official business), `travel` credits it as a travel day,
     * and `times` replaces the day's in and out times. The raw punches are
     * never touched.
     */
    public function up(): void
    {
        Schema::create('dtr_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('kind', 20);
            $table->time('time_in')->nullable();
            $table->time('time_out')->nullable();
            $table->string('remark');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_adjustments');
    }
};
