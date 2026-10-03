<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-4: the plantilla of positions, who holds each item (one row per
     * period of service on an item — HR-5's Service Record lines), and the
     * salary schedules (grade × step) that price them.
     */
    public function up(): void
    {
        // A salary schedule as issued (e.g. the 3rd tranche, NBC No. 601),
        // in force from its effective date until the next one.
        Schema::create('salary_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('reference')->nullable();
            $table->date('effective_date')->unique();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('salary_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('salary_grade');
            $table->unsignedTinyInteger('step');
            $table->decimal('monthly_salary', 12, 2);

            $table->unique(['salary_schedule_id', 'salary_grade', 'step']);
        });

        Schema::create('plantilla_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_number', 100)->unique();
            $table->string('position_title');
            $table->unsignedTinyInteger('salary_grade');
            $table->string('level', 10)->nullable();
            $table->foreignId('organizational_unit_id')->nullable()->constrained()->nullOnDelete();
            // The authority creating the item (e.g. the DBM-approved PSIPOP).
            $table->string('authority')->nullable();
            $table->text('remarks')->nullable();
            // Abolished items stay for the record; they're never deleted.
            $table->timestamp('abolished_at')->nullable();
            $table->date('abolished_on')->nullable();
            $table->string('abolition_authority')->nullable();
            $table->timestamps();

            $table->index(['organizational_unit_id', 'salary_grade']);
        });

        Schema::create('plantilla_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('status', 20);
            $table->string('nature', 30);
            $table->date('date_from');
            // Null while they still hold it.
            $table->date('date_to')->nullable();
            $table->string('end_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['plantilla_item_id', 'date_from']);
            $table->index(['employee_id', 'date_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_appointments');
        Schema::dropIfExists('plantilla_items');
        Schema::dropIfExists('salary_rates');
        Schema::dropIfExists('salary_schedules');
    }
};
