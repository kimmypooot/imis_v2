<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_correction_requests', function (Blueprint $table) {
            $table->foreignId('employee_id')->after('id')->constrained()->cascadeOnDelete();
            $table->string('reference_number')->nullable()->unique()->after('employee_id');
            $table->string('field', 40)->after('reference_number');
            $table->text('current_value')->nullable()->after('field');
            $table->text('proposed_value')->after('current_value');
            $table->text('reason')->after('proposed_value');
            $table->string('status', 20)->default('pending')->index()->after('reason');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete()->after('status');
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->text('remarks')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('data_correction_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['reference_number', 'field', 'current_value', 'proposed_value', 'reason', 'status', 'decided_at', 'remarks']);
        });
    }
};
