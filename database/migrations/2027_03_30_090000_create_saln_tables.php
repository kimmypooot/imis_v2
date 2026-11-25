<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SALN (RA 6713 §8, 2025 SALN Form). A filing holds the statement being
 * encoded; each printout freezes it into an immutable version with a
 * reference number; events are its timeline; exemptions are HRD's.
 * Statements are encrypted with APP_KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 10);
            $table->date('reckoning_date');
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_version')->default(0);
            $table->unsignedSmallInteger('received_version')->nullable();
            $table->string('oath_mode', 20)->default('regional_director');
            $table->string('officer_name')->nullable();
            $table->string('officer_title')->nullable();
            $table->longText('statement');
            $table->foreignId('employee_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'type', 'reckoning_date']);
            $table->index('status');
        });

        Schema::create('saln_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saln_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('reference', 40)->index();
            $table->longText('statement');
            $table->string('oath_mode', 20);
            $table->string('officer_name')->nullable();
            $table->string('officer_title')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['saln_id', 'version']);
        });

        Schema::create('saln_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saln_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);
            $table->unsignedSmallInteger('version')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('override')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['saln_id', 'id']);
        });

        Schema::create('saln_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saln_exemptions');
        Schema::dropIfExists('saln_events');
        Schema::dropIfExists('saln_versions');
        Schema::dropIfExists('salns');
    }
};
