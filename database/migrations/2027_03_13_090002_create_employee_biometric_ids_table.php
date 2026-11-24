<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: which employee a device's biometric ID belongs to, for a date
     * range (an ID can change owner or be re-enrolled). A row with no
     * employee and `ignored` set marks an ID HRD chose to disregard.
     */
    public function up(): void
    {
        Schema::create('employee_biometric_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_device_id')->constrained()->cascadeOnDelete();
            $table->string('biometric_id', 20);
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('ignored')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['biometric_device_id', 'biometric_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_biometric_ids');
    }
};
