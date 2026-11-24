<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: raw punches from the devices, append-only. Only the ID and the
     * time are kept (the device in/out flag is unreliable). The same punch
     * is never stored twice, so a full-history file can be uploaded again
     * safely.
     */
    public function up(): void
    {
        Schema::create('biometric_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_device_id')->constrained()->restrictOnDelete();
            $table->string('biometric_id', 20);
            $table->dateTime('punched_at');
            $table->foreignId('biometric_import_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['biometric_device_id', 'biometric_id', 'punched_at'], 'biometric_punches_unique');
            $table->index(['biometric_device_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_punches');
    }
};
