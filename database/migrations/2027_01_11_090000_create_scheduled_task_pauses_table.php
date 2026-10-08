<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-3: scheduled tasks an administrator has paused. The scheduler
     * skips a task while it has a row here (routes/console.php); keyed by
     * the task's name as Admin → Scheduled Tasks shows it.
     */
    public function up(): void
    {
        Schema::create('scheduled_task_pauses', function (Blueprint $table) {
            $table->id();
            $table->string('task', 191)->unique();
            $table->string('reason', 255)->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_pauses');
    }
};
