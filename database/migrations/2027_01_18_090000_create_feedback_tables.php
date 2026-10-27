<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feedback, suggestions and recommendations sent by signed-in users,
     * and the thread of replies (and private notes) on each.
     */
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('subject', 150);
            $table->text('message');
            // Where the sender was (path only, never the query string).
            $table->string('page_path', 255)->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('feedback_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->constrained('feedback')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            // A private note: only people who manage feedback ever see it.
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['feedback_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_replies');
        Schema::dropIfExists('feedback');
    }
};
