<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR corrects a month imported from the leave card file in IMIS. The
     * figures as first imported are kept in `imported_values`, with who corrected
     * them, when and why; a later import of the file leaves a corrected
     * month alone.
     */
    public function up(): void
    {
        Schema::table('leave_card_months', function (Blueprint $table) {
            $table->json('imported_values')->nullable()->after('applications');
            $table->string('correction_reason', 300)->nullable()->after('imported_values');
            $table->foreignId('corrected_by')->nullable()->after('correction_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable()->after('corrected_by');
        });
    }

    public function down(): void
    {
        Schema::table('leave_card_months', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn(['imported_values', 'correction_reason', 'corrected_at']);
        });
    }
};
