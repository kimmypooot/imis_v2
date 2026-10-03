<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-2b: signed PDS submissions. Each row is an immutable copy of the
     * whole PDS exactly as the employee e-signed it. HR verifies or
     * returns that copy, never the live data, which may have moved on.
     * Verified rows are the PDS history kept in the 201 file.
     */
    public function up(): void
    {
        Schema::create('pds_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            // submitted → verified | returned, or superseded by a newer
            // submission before review.
            $table->string('status', 20);
            // The whole PDS as signed, encrypted (it holds the sensitive
            // fields too).
            $table->longText('snapshot');
            // HMAC of the signed content, to tell whether the live PDS
            // has changed since.
            $table->char('fingerprint', 64);
            $table->string('photo_path')->nullable();
            // The e-signature: who accepted the item 42 declaration and
            // re-entered their password, when, and from where.
            $table->foreignId('signed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('signed_at');
            $table->string('signed_ip', 45)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'signed_at']);
        });

        Schema::table('pds_profiles', function (Blueprint $table) {
            // The PDS photo (passport size), on the private disk.
            $table->string('photo_path')->nullable()->after('government_id_issuance');
        });
    }

    public function down(): void
    {
        Schema::table('pds_profiles', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });

        Schema::dropIfExists('pds_submissions');
    }
};
