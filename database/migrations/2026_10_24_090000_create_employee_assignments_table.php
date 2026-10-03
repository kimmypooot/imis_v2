<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-1b: an employee's movements between offices — reassignments,
     * details, designations and returns to the mother unit — with the
     * order that authorized each. Only a reassignment moves the account's
     * office (`applied_at` = when it did); the others are history.
     */
    public function up(): void
    {
        Schema::create('employee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignId('from_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->foreignId('to_unit_id')->constrained('organizational_units')->restrictOnDelete();
            $table->date('effective_date');
            // Details and designations; a reassignment ends where the next one starts.
            $table->date('end_date')->nullable();
            $table->string('order_number', 100)->nullable();
            $table->date('order_date')->nullable();
            $table->text('remarks')->nullable();
            // The scanned order, kept in the 201 file as an Office Order.
            $table->foreignId('document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'effective_date']);
            $table->index(['kind', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_assignments');
    }
};
