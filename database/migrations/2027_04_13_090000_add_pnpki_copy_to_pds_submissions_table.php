<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signed PDS can have its PNPKI-signed PDF attached: the copy filed in the
 * 201 File (`pnpki_document_id`) and what was found when it was checked
 * (`pnpki_signatures`: each signature's signer, issuer, time and result).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pds_submissions', function (Blueprint $table) {
            $table->foreignId('pnpki_document_id')->nullable()->after('signature_path')->constrained('employee_documents')->nullOnDelete();
            $table->json('pnpki_signatures')->nullable()->after('pnpki_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('pds_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pnpki_document_id');
            $table->dropColumn('pnpki_signatures');
        });
    }
};
