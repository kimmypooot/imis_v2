<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-6b: leave applications (CS Form No. 6) and their approval chain
     * (Section 7: supervisor recommends → HR certifies credits → Head of
     * Agency approves), officer-in-charge designations for the Head, and
     * Laravel's database notifications (the top-bar bell).
     */
    public function up(): void
    {
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            // LV-YYYY-#####, as in the legacy LMS.
            $table->string('reference_number', 30)->nullable()->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->boolean('is_half_day')->default(false);
            $table->string('half_day_period', 2)->nullable();
            $table->decimal('days', 7, 3);
            // Set when approved (7.C): with pay is charged to the credits.
            $table->decimal('days_with_pay', 7, 3)->nullable();
            $table->decimal('days_without_pay', 7, 3)->nullable();
            // 6.B Details of leave: which box, and what's written beside it.
            $table->string('detail', 30)->nullable();
            $table->string('detail_text', 500)->nullable();
            $table->boolean('commutation_requested')->default(false);
            $table->text('remarks')->nullable();
            // for_review, returned, approved, disapproved, cancelled.
            $table->string('status', 20)->default('for_review');
            $table->unsignedTinyInteger('current_step')->nullable();
            // A supporting document (e.g. a medical certificate): private disk.
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date_from']);
            $table->index(['status', 'date_from']);
        });

        Schema::create('leave_application_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')->constrained()->cascadeOnDelete();
            // One round per submission; a return-and-resubmit starts a new one.
            $table->unsignedTinyInteger('round')->default(1);
            $table->unsignedTinyInteger('step');
            // CS Form 6 part: B (recommendation), A (certification), C (approval).
            $table->string('part', 1);
            // supervisor, hr, head.
            $table->string('role', 20);
            // The supervisor, named at submission; HR and the Head act as a role.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // pending, recommended, not_recommended, certified,
            // insufficient_credits, approved, disapproved, returned.
            $table->string('action', 30)->default('pending');
            $table->text('remarks')->nullable();
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            // Set when an officer-in-charge acted for the Head of Agency.
            $table->foreignId('acted_for')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            // 7.A: the credits HR certified (type code => balance), frozen.
            $table->json('certified_credits')->nullable();
            $table->timestamps();

            $table->index(['leave_application_id', 'round', 'step'], 'leave_steps_application_round_step_index');
            $table->index(['role', 'action'], 'leave_steps_role_action_index');
        });

        // Officer-in-charge for the Head of Agency (legacy lms_agency_designations).
        Schema::create('leave_designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('designee_id')->constrained('users')->restrictOnDelete();
            // Whom they act for (the Head of Agency), for the record.
            $table->foreignId('acting_for_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('order_number', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date_from', 'date_to']);
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('leave_designations');
        Schema::dropIfExists('leave_application_steps');
        Schema::dropIfExists('leave_applications');
    }
};
