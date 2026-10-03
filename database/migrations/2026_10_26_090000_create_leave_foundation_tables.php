<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-6a: leave foundations (ports the legacy LMS, `old-imis/leave`).
     * Leave types are policy as data; the credit ledger is append-only (a
     * balance is the sum of its entries); holidays drive working-day
     * counts. Filing and approval come in HR-6b.
     */
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // CS Form 6 Section 7 route: standard (supervisor → HR → head)
            // or special (HR → head).
            $table->string('workflow', 20)->default('standard');
            // Whose credits a filing uses: its own ledger, or another type's
            // (Mandatory/Forced Leave is charged to Vacation Leave). Null on a
            // type with no balance (e.g. maternity).
            $table->boolean('requires_balance')->default(false);
            $table->foreignId('charged_to_id')->nullable()->constrained('leave_types')->nullOnDelete();
            // Earned monthly (VL/SL), or granted each January (SPL, …).
            $table->decimal('monthly_accrual', 6, 3)->nullable();
            $table->decimal('annual_grant', 6, 3)->nullable();
            // Unused credits lapse at year end (non-cumulative leaves).
            $table->boolean('lapses_yearly')->default(false);
            $table->decimal('max_days_per_year', 7, 2)->nullable();
            $table->decimal('max_days_per_filing', 7, 2)->nullable();
            // Eligibility.
            $table->string('sex', 10)->nullable();
            $table->json('eligible_statuses')->nullable();
            $table->unsignedSmallInteger('min_service_months')->default(0);
            $table->boolean('requires_solo_parent_id')->default(false);
            // Filing rules.
            $table->unsignedSmallInteger('advance_filing_days')->default(0);
            $table->boolean('allows_half_day')->default(true);
            // Counted in calendar days rather than working days (maternity).
            $table->boolean('counts_calendar_days')->default(false);
            $table->unsignedSmallInteger('medical_certificate_after_days')->nullable();
            $table->string('supporting_documents', 500)->nullable();
            $table->boolean('monetizable')->default(false);
            $table->string('policy_reference')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name');
            // regular, special_non_working (both off work) or special_working.
            $table->string('type', 30);
            $table->string('proclamation')->nullable();
            $table->timestamps();

            $table->unique(['date', 'name']);
            $table->index('date');
        });

        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('entry_date');
            // opening, accrual, grant, deduction, adjustment, lapse, monetization.
            $table->string('kind', 20);
            // Signed: credits are positive, deductions negative.
            $table->decimal('amount', 8, 3);
            $table->string('particulars', 500);
            // For an accrual or grant: the period it covers (no double posting).
            $table->unsignedSmallInteger('period_year')->nullable();
            $table->unsignedTinyInteger('period_month')->nullable();
            // An entry that cancels another (corrections never edit or delete).
            $table->foreignId('reverses_id')->nullable()->constrained('leave_ledger_entries')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Short names: MySQL caps identifiers at 64 characters.
            $table->index(['employee_id', 'leave_type_id', 'entry_date'], 'leave_ledger_employee_type_date_index');
            $table->index(['leave_type_id', 'kind', 'period_year', 'period_month'], 'leave_ledger_type_kind_period_index');
        });

        // Leave settings on the employee record: who recommends their leave
        // (legacy "no supervisor = no workflow") and solo-parent status.
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('immediate_supervisor_id')->nullable()->after('remarks')->constrained('users')->nullOnDelete();
            $table->boolean('is_solo_parent')->default(false)->after('immediate_supervisor_id');
            // The ID number is personal data: encrypted (APP_KEY).
            $table->text('solo_parent_id_number')->nullable()->after('is_solo_parent');
            $table->date('solo_parent_id_expiry')->nullable()->after('solo_parent_id_number');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('immediate_supervisor_id');
            $table->dropColumn(['is_solo_parent', 'solo_parent_id_number', 'solo_parent_id_expiry']);
        });

        Schema::dropIfExists('leave_ledger_entries');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('leave_types');
    }
};
