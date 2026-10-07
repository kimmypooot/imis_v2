<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Head-of-office designations: someone who heads another office in a
     * concurrent capacity (e.g. a Field Director over two field offices for
     * want of a second Director II item) without moving there.
     *
     * - `as_head`: the designation is to head the office, not only to serve
     *   in it. It widens the designee's office-scoped access and shows on
     *   the Staffing page.
     * - `hand_over_supervision` / `restore_supervision`: whether the office's
     *   staff take the designee as immediate supervisor when it starts, and
     *   get their previous supervisors back when it ends;
     *   `supervision_handed_at` / `supervision_restored_at` say when that
     *   happened (each once).
     * - `supervisor_handovers`: who each staff member's supervisor was
     *   before, so it can be given back.
     */
    public function up(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->boolean('as_head')->default(false)->after('kind');
            $table->boolean('hand_over_supervision')->default(false)->after('applied_at');
            $table->timestamp('supervision_handed_at')->nullable()->after('hand_over_supervision');
            $table->boolean('restore_supervision')->default(false)->after('supervision_handed_at');
            $table->timestamp('supervision_restored_at')->nullable()->after('restore_supervision');
        });

        Schema::create('supervisor_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('employee_assignments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            // Null while the designee is still their supervisor through this handover.
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_handovers');

        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->dropColumn(['as_head', 'hand_over_supervision', 'supervision_handed_at', 'restore_supervision', 'supervision_restored_at']);
        });
    }
};
