<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-1: the employee record, one per user account. Identity (name,
     * sex, birthday, position, office) stays on `users`. This table holds
     * only the employment facts HR owns. Later HR phases (PDS, L&D,
     * plantilla, service record, leave) hang off it.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            // An HR record must never vanish with its account, so a user
            // with an employee record can't be hard-deleted.
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('employee_number', 30)->nullable()->unique();
            $table->string('employment_status', 30);
            // Legacy users_cscro8.first_day.
            $table->date('original_appointment_date')->nullable();
            $table->date('separation_date')->nullable();
            $table->string('separation_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('employment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
