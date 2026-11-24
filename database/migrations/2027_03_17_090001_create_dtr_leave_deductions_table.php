<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: a month's tardiness and undertime charged to leave credits (CSC
     * Table IV, MC No. 14, s. 1999). The leave ledger entry holds the days
     * actually taken off the credits; whatever the credits could not cover
     * is kept here as days without pay (no ledger entry at all when there
     * were no credits). The row also remembers which month it was for and
     * how many minutes it came from, so the month is charged once (until the
     * charge is reversed) and a DTR changed afterwards can be flagged.
     */
    public function up(): void
    {
        Schema::create('dtr_leave_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedInteger('minutes');
            // Taken off the credits (the ledger entry), and the part that could not be: leave without pay.
            $table->decimal('days', 8, 3);
            $table->decimal('days_without_pay', 8, 3)->default(0);
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_ledger_entry_id')->nullable()->constrained('leave_ledger_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'period_year', 'period_month'], 'dtr_leave_deductions_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_leave_deductions');
    }
};
