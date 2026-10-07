<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-5: Service Record lines HR enters by hand — service in another
 * government agency before the CSC, which no plantilla item here holds.
 * Shown with the plantilla lines, by date, in the same CSC columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_record_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('designation');
            $table->string('status', 50);
            $table->unsignedTinyInteger('salary_grade')->nullable();
            $table->unsignedTinyInteger('step')->nullable();
            $table->decimal('annual_salary', 12, 2)->nullable();
            $table->string('agency');
            $table->string('station')->nullable();
            $table->string('branch', 50);
            $table->decimal('leave_without_pay', 7, 3)->nullable();
            $table->date('separation_date')->nullable();
            $table->string('separation_cause')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'date_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_record_entries');
    }
};
