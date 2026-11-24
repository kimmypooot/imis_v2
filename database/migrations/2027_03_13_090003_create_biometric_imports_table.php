<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: one row per uploaded log file, with what it added.
     */
    public function up(): void
    {
        Schema::create('biometric_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_device_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('file_name');
            $table->string('file_hash', 64);
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_new')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->dateTime('first_punch_at')->nullable();
            $table->dateTime('last_punch_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_imports');
    }
};
