<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-8: payroll and payslips.
     *
     * - pay_components: allowance, deduction and loan types, as data.
     * - employee_pay_items / employee_loans: each person's recurring items
     *   and loans. A loan's balance is never stored: it is what was payable,
     *   less what was paid before IMIS, less the finalized payroll lines.
     * - payroll_runs → payroll_entries → payroll_lines: one monthly payroll,
     *   net pay released in two halves (15th and 30th). Entries keep a
     *   snapshot of who the person was then (name, item, grade, office).
     * - payslip_requests: an employee asks for a month's payslip; the head
     *   of HRD (or whoever is designated head) decides.
     */
    public function up(): void
    {
        Schema::create('pay_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('kind', 20); // PayComponentKind
            $table->string('method', 20); // PayComputation
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('rate', 6, 3)->nullable();
            $table->decimal('base_min', 12, 2)->nullable();
            $table->decimal('base_max', 12, 2)->nullable();
            $table->boolean('automatic')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_pay_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('pay_component_id')->constrained()->restrictOnDelete();
            $table->string('reference', 60)->nullable();
            $table->date('granted_on')->nullable();
            $table->decimal('principal', 12, 2);
            $table->decimal('total_payable', 12, 2);
            $table->decimal('monthly_amortization', 12, 2);
            $table->date('start_month');
            $table->decimal('paid_before', 12, 2)->default(0);
            $table->date('stopped_on')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'start_month']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 20)->default('draft'); // PayrollRunStatus
            $table->date('first_release_on')->nullable();
            $table->date('second_release_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('item_number', 100)->nullable();
            $table->unsignedTinyInteger('salary_grade')->nullable();
            $table->unsignedTinyInteger('step')->nullable();
            $table->string('office')->nullable();
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->decimal('first_half', 12, 2)->default(0);
            $table->decimal('second_half', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // earning | deduction
            $table->foreignId('pay_component_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_loan_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('label', 120);
            $table->decimal('amount', 12, 2);
            $table->boolean('adjusted')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payslip_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 30)->nullable()->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_entry_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // PayslipRequestStatus
            $table->string('purpose', 255)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acting_for_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_requests');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_loans');
        Schema::dropIfExists('employee_pay_items');
        Schema::dropIfExists('pay_components');
    }
};
