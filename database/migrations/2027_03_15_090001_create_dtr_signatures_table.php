<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: an employee signing one finished sheet of their DTR. The hash
     * of the sheet as signed is kept, so anything that changes it later
     * (an adjustment, a corrected punch) shows as "changed since signed".
     * Signing again replaces the earlier signing; the old code stays and
     * says it was replaced. The signature image is a snapshot copy.
     */
    public function up(): void
    {
        Schema::create('dtr_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('code', 16)->unique();
            $table->string('content_hash', 64);
            $table->string('signature_path')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_signatures');
    }
};
