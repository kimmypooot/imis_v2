<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signed PDS keeps its own copy of the signer's e-signature image
 * (Signatures::snapshot), so it prints with it, unchanged by later
 * changes to their signature. Null when they had none saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pds_submissions', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('signed_ip');
        });
    }

    public function down(): void
    {
        Schema::table('pds_submissions', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }
};
