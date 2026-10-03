<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-2c: the documents in an employee's 201 file (appointment, oath,
     * diplomas, clearances, …). Files live on the private disk and are
     * only served through authorized routes. Nothing is ever deleted: a
     * wrong or outdated upload is archived with a reason, so the 201 file
     * keeps its full history.
     */
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 40);
            $table->string('title')->nullable();
            $table->date('document_date')->nullable();
            $table->text('remarks')->nullable();

            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            // Integrity: the file can be checked against what was uploaded.
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('archive_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
