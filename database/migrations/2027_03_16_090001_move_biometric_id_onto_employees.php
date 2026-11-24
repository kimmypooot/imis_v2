<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DTR: the biometric ID is a field on the employee's record, like the
     * employee number: the number their fingerprint or face is enrolled
     * under on the biometric device, which is how their punches find them.
     * This replaces the separate devices and date-ranged ID mappings.
     *
     * Existing mappings are carried over: each employee keeps their current
     * (or latest) ID, unless another employee had the same number on another
     * device (those are left blank for HR).
     * Punches are then kept once per ID and time, whichever device they came
     * from. Raw punches are never deleted, except an exact repeat of the
     * same ID and time that two devices both recorded.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('biometric_id', 20)->nullable()->unique()->after('employee_number');
        });

        $this->carryOverMappings();

        // Keep one of any punch recorded twice (same ID and time), so the new key can hold.
        DB::statement('DELETE FROM biometric_punches WHERE id NOT IN (SELECT id FROM (SELECT MIN(id) AS id FROM biometric_punches GROUP BY biometric_id, punched_at) AS kept)');

        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->dropForeign(['biometric_device_id']);
            $table->dropUnique('biometric_punches_unique');
            $table->dropIndex(['biometric_device_id', 'punched_at']);
            $table->dropColumn('biometric_device_id');
        });

        Schema::table('biometric_punches', function (Blueprint $table) {
            $table->unique(['biometric_id', 'punched_at'], 'biometric_punches_unique');
            $table->index('punched_at');
        });

        Schema::table('biometric_imports', function (Blueprint $table) {
            $table->dropForeign(['biometric_device_id']);
            $table->dropColumn('biometric_device_id');
        });

        Schema::dropIfExists('employee_biometric_ids');
        Schema::dropIfExists('biometric_devices');
    }

    public function down(): void
    {
        throw new RuntimeException('The biometric devices and ID mappings were folded into the employee record and cannot be rebuilt.');
    }

    /**
     * Each employee keeps their current (else latest) mapping. A number that
     * two different employees held on different devices is left blank for
     * HR to settle: guessing would credit one person's punches to another.
     */
    private function carryOverMappings(): void
    {
        $mappings = DB::table('employee_biometric_ids')
            ->whereNotNull('employee_id')->where('ignored', false)
            ->orderByRaw('valid_to is null desc')->orderByDesc('valid_to')->orderByDesc('valid_from')->orderByDesc('id')
            ->get();

        $chosen = [];
        foreach ($mappings as $mapping) {
            $chosen[$mapping->employee_id] ??= $mapping->biometric_id;
        }

        $holders = array_count_values($chosen);

        foreach ($chosen as $employeeId => $biometricId) {
            if ($holders[$biometricId] === 1) {
                DB::table('employees')->where('id', $employeeId)->update(['biometric_id' => $biometricId]);
            }
        }
    }
};
