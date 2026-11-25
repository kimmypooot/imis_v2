<?php

use Database\Seeders\HrRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-8: HRD prepares the payroll, submits it to MSD where the Accountant
     * III reviews it and enters withholding tax, and gets it back to
     * finalize. The run keeps who submitted and returned it, when, and
     * Accounting's note. Adds the `payroll.tax` permission and the
     * "Accountant" starter role (an existing role is never touched).
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('release_on');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('submitted_by');
            $table->foreignId('returned_by')->nullable()->after('returned_at')->constrained('users')->nullOnDelete();
            $table->string('accounting_note', 1000)->nullable()->after('returned_by');
        });

        (new RolePermissionSeeder)->run();
        (new HrRoleSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['submitted_at', 'returned_at', 'accounting_note']);
        });
    }
};
