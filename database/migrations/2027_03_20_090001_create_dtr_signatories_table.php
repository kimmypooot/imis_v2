<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who an employee picked to verify one DTR sheet (the signatory printed
     * under "Office Head Name/Signature"), for that employee and period. No
     * row means the office's head applies.
     */
    public function up(): void
    {
        Schema::create('dtr_signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->foreignId('signatory_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('chosen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_signatories');
    }
};
