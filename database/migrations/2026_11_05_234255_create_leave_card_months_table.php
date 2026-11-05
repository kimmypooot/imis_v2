<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-6: months of a leave card kept as history — what the paper/Excel
     * card showed for months before the credit ledger takes over (see
     * imis:import-leave-card-history). Display only: balances always come
     * from the ledger, so these rows never change what anyone has left.
     */
    public function up(): void
    {
        Schema::create('leave_card_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            // Vacation Leave: earned that month, absences/undertime with pay, month-end balance.
            $table->decimal('vl_earned', 8, 3)->nullable();
            $table->decimal('vl_used', 8, 3)->nullable();
            $table->decimal('vl_balance', 9, 3)->nullable();
            $table->decimal('sl_earned', 8, 3)->nullable();
            $table->decimal('sl_used', 8, 3)->nullable();
            $table->decimal('sl_balance', 9, 3)->nullable();
            // The card's "Particulars" and "Date and action taken on application" text.
            $table->string('particulars', 500)->nullable();
            $table->string('applications', 1000)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_card_months');
    }
};
