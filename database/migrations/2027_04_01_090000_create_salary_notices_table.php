<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notices of Salary Adjustment (NOSA) and of Step Increment (NOSI).
 *
 * Each notice is a snapshot of exactly what was printed, so a later change
 * to the plantilla or a schedule never alters a notice already signed. A
 * notice is filed once its signed copy is in the 201 File
 * (`employee_document_id`); until then it can be deleted and generated
 * again.
 *
 * Salary schedules gain what a NOSA's opening paragraph quotes: the date
 * of the issuance ("National Budget Circular No. 601 s. 2026 dated
 * 22 January 2026") and the law it implements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_schedules', function (Blueprint $table) {
            $table->date('issuance_date')->nullable()->after('reference');
            $table->string('implements', 255)->nullable()->after('issuance_date');
        });

        Schema::create('salary_notices', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plantilla_appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salary_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('notice_date');
            $table->date('effective_date');
            $table->text('basis');
            $table->string('addressee', 255);
            $table->string('salutation', 255)->nullable();
            $table->string('position_title', 255);
            $table->string('item_number', 100)->nullable();
            $table->unsignedTinyInteger('salary_grade');
            $table->unsignedTinyInteger('from_step');
            $table->decimal('from_salary', 12, 2);
            $table->unsignedTinyInteger('to_step');
            $table->decimal('to_salary', 12, 2);
            $table->string('signatory_name', 255);
            $table->string('signatory_position', 255);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('filed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'effective_date']);
            $table->index(['employee_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_notices');

        Schema::table('salary_schedules', function (Blueprint $table) {
            $table->dropColumn(['issuance_date', 'implements']);
        });
    }
};
