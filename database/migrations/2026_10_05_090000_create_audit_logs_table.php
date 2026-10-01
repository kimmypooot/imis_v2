<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Deliberately no foreign key: an audit record must outlive
            // (and never be rewritten by) changes to the row it points at.
            // `actor_label`/`target_label` snapshot who/what it was at the
            // time, so the entry stays readable even if that row is gone.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('event', 64);
            $table->string('action', 32);
            $table->string('target_type', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label')->nullable();
            $table->string('description', 500);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            // Append-only: there is no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // Every admin filter is "<column> = ?, newest first", so each
            // index ends in created_at to serve both the filter and the sort.
            $table->index('created_at');
            $table->index(['event', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['target_type', 'target_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
