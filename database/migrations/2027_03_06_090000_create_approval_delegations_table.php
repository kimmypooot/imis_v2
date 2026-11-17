<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('reason', 200)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['delegate_id', 'date_from', 'date_to']);
            $table->index(['delegator_id', 'date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
    }
};
