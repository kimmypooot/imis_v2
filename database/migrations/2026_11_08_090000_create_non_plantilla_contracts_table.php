<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts and appointments of staff who hold no plantilla item: casual,
 * contractual, Job Order and Contract of Service (the Non-Plantilla page).
 * One row per contract period; renewals are new rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_plantilla_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // casual, contractual, job_order, contract_of_service.
            $table->string('employment_status', 30);
            $table->string('designation', 150);
            $table->foreignId('organizational_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date_from');
            // Null: open-ended (until terminated).
            $table->date('date_to')->nullable();
            $table->decimal('rate', 12, 2)->nullable();
            // daily or monthly.
            $table->string('rate_basis', 10)->nullable();
            $table->string('funding_source', 150)->nullable();
            // Contract / appointment / order number.
            $table->string('reference', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'date_from']);
            $table->index(['employment_status', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_plantilla_contracts');
    }
};
