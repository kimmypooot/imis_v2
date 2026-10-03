<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-3a: the PDS Section VI table becomes the Learning & Development
     * record (replacing ldms's ld_cscro8), so a training is entered once
     * and shows on the L&D log, the PDS, and HRD's reports.
     *
     * Adds ldms's fields (modality, venue, resource speaker), HRD approval
     * (employee entries start pending), an optional certificate on the
     * private disk, who entered it, soft deletes, and ids kept from ldms
     * (legacy_id for a re-runnable import; otrs_training_id, unused yet).
     */
    public function up(): void
    {
        Schema::table('pds_learning_developments', function (Blueprint $table) {
            // ld_type now holds a code (LdType); "Other" is specified here.
            $table->string('ld_type_other', 100)->nullable()->after('ld_type');
            // Null = not recorded (ldms never really captured it).
            $table->string('modality', 20)->nullable()->after('ld_type_other');
            $table->string('venue')->nullable()->after('sponsor');
            $table->string('resource_speaker', 512)->nullable()->after('venue');

            $table->string('approval_status', 20)->default('pending')->after('resource_speaker');
            $table->foreignId('reviewed_by')->nullable()->after('approval_status')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            $table->foreignId('created_by')->nullable()->after('rejection_reason')->constrained('users')->restrictOnDelete();

            $table->string('certificate_path')->nullable()->after('created_by');
            $table->string('certificate_name')->nullable()->after('certificate_path');
            $table->string('certificate_mime', 100)->nullable()->after('certificate_name');
            $table->unsignedInteger('certificate_size')->nullable()->after('certificate_mime');

            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->after('certificate_size');
            $table->unsignedBigInteger('otrs_training_id')->nullable()->after('legacy_id');
            $table->softDeletes();

            $table->index(['approval_status', 'date_from']);
        });

        // HR-2a stored the type as free text; it's now a code (LdType).
        // Known words become their code; anything else becomes "Other",
        // keeping what was typed as the specification.
        $codes = ['managerial' => 'managerial', 'supervisory' => 'supervisory', 'technical' => 'technical', 'foundation' => 'foundation',
            'leadership and management' => 'managerial'];

        DB::table('pds_learning_developments')->whereNotNull('ld_type')->orderBy('id')->each(function ($row) use ($codes) {
            $code = $codes[mb_strtolower(trim($row->ld_type))] ?? null;

            DB::table('pds_learning_developments')->where('id', $row->id)->update($code
                ? ['ld_type' => $code]
                : ['ld_type' => 'other', 'ld_type_other' => mb_substr($row->ld_type, 0, 100)]);
        });
    }

    public function down(): void
    {
        Schema::table('pds_learning_developments', function (Blueprint $table) {
            $table->dropIndex(['approval_status', 'date_from']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropUnique(['legacy_id']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'ld_type_other', 'modality', 'venue', 'resource_speaker', 'approval_status', 'reviewed_at', 'rejection_reason',
                'certificate_path', 'certificate_name', 'certificate_mime', 'certificate_size', 'legacy_id', 'otrs_training_id',
            ]);
        });
    }
};
