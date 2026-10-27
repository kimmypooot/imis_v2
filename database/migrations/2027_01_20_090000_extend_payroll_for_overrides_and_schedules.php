<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-8 extension: the earnings & deductions module.
     *
     * - pay_components.category groups deductions on the employee's page
     *   (mandatory contributions, government loans…).
     * - payroll_lines keep the configured amount when HR overrides it, who
     *   and when and why, and whether a deduction was later marked paid
     *   (remitted) as opposed to merely confirmed on a finalized payroll.
     * - payroll_entries can be cancelled (kept for the audit trail).
     * - payroll_runs keep why they were last reopened.
     * - employee_loans can be cancelled (stopped_on is also set, so a
     *   cancelled loan deducts nothing).
     */
    public function up(): void
    {
        Schema::table('pay_components', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('kind');
        });

        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->decimal('original_amount', 12, 2)->nullable()->after('amount');
            $table->foreignId('adjusted_by')->nullable()->after('adjusted')->constrained('users')->nullOnDelete();
            $table->timestamp('adjusted_at')->nullable()->after('adjusted_by');
            $table->string('adjust_reason', 500)->nullable()->after('adjusted_at');
            $table->date('remitted_on')->nullable()->after('adjust_reason');
            $table->foreignId('remitted_by')->nullable()->after('remitted_on')->constrained('users')->nullOnDelete();
        });

        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('second_half');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->after('cancelled_by');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('reopen_reason', 500)->nullable()->after('remarks');
        });

        Schema::table('employee_loans', function (Blueprint $table) {
            $table->date('cancelled_on')->nullable()->after('stopped_on');
        });

        $categories = [
            'mandatory' => ['GSIS-PS', 'PHIC', 'HDMF', 'WTAX'],
            'government_loan' => ['GSIS-CONSO', 'GSIS-MPL', 'GSIS-EML', 'GSIS-POL', 'HDMF-MPL', 'HDMF-CAL'],
            'other_loan' => ['LBP', 'COOP-LOAN'],
        ];
        foreach ($categories as $category => $codes) {
            DB::table('pay_components')->whereIn('code', $codes)->update(['category' => $category]);
        }
        DB::table('pay_components')->whereNull('category')->where('kind', 'earning')->update(['category' => 'allowance']);
        DB::table('pay_components')->whereNull('category')->where('kind', 'loan')->update(['category' => 'other_loan']);
        DB::table('pay_components')->whereNull('category')->update(['category' => 'other']);
    }

    public function down(): void
    {
        Schema::table('employee_loans', fn (Blueprint $table) => $table->dropColumn('cancelled_on'));
        Schema::table('payroll_runs', fn (Blueprint $table) => $table->dropColumn('reopen_reason'));
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjusted_by');
            $table->dropConstrainedForeignId('remitted_by');
            $table->dropColumn(['original_amount', 'adjusted_at', 'adjust_reason', 'remitted_on']);
        });
        Schema::table('pay_components', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
