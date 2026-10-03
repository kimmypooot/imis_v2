<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-6c: leave monetization and terminal leave (legacy
     * lms_monetization_requests), and flexible work arrangements with their
     * weekly field activity reports (legacy lms_fwa_arrangements / lms_fwa_far).
     */
    public function up(): void
    {
        Schema::create('leave_monetizations', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 30)->nullable()->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            // partial (up to half the VL) or terminal (all VL + SL on separation).
            $table->string('kind', 20);
            $table->decimal('vl_days', 8, 3)->default(0);
            $table->decimal('sl_days', 8, 3)->default(0);
            // Balances and salary when it was requested, frozen.
            $table->decimal('vl_balance', 8, 3);
            $table->decimal('sl_balance', 8, 3);
            $table->decimal('monthly_salary', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('calendar_year');
            // pending, approved, rejected, released.
            $table->string('status', 20)->default('pending');
            $table->text('remarks')->nullable();
            $table->text('decision_remarks')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'calendar_year']);
            $table->index('status');
        });

        Schema::create('fwa_arrangements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            // flexitime, cww (compressed work week), wfh, combination.
            $table->string('type', 20);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // Day => office / wfh / off (Mon…Sun); a CWW's off day is skipped
            // when counting leave days.
            $table->json('schedule')->nullable();
            $table->string('daily_hours', 50)->nullable();
            $table->text('remarks')->nullable();
            // pending, approved, rejected, cancelled, terminated.
            $table->string('status', 20)->default('pending');
            $table->text('decision_remarks')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('fwa_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fwa_arrangement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            // The week (Monday to Friday) it reports on.
            $table->date('week_start');
            $table->date('week_end');
            $table->text('targets');
            $table->text('accomplishments');
            $table->text('remarks')->nullable();
            // submitted, acknowledged.
            $table->string('status', 20)->default('submitted');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_remarks')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fwa_reports');
        Schema::dropIfExists('fwa_arrangements');
        Schema::dropIfExists('leave_monetizations');
    }
};
