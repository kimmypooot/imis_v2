<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filing rules from the CSC leave rules the leave types couldn't express
 * yet (all editable in Admin → Leave Types), and what an application
 * needs to record for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // "File N days in advance, whenever possible" (VL): later, with a reason, instead of refused.
            $table->boolean('late_filing_with_reason')->default(false)->after('allows_emergency_filing');
            // Days beyond the credits are approved without pay instead of refused (VL, FL, SL).
            $table->boolean('excess_without_pay')->default(false)->after('requires_balance');
            // When its own credits run out, the next ones used (SL → VL; MC 41, s. 1998, Sec. 56).
            $table->foreignId('excess_charged_to_id')->nullable()->after('charged_to_id')->constrained('leave_types')->nullOnDelete();
            // SL filed in advance needs a medical certificate (CS Form No. 6 instructions).
            $table->boolean('medical_certificate_if_advance')->default(false)->after('medical_certificate_after_days');
            // A supporting document must be attached (e.g. birth certificate for paternity leave).
            $table->boolean('requires_attachment')->default(false)->after('supporting_documents');
            // Maternity: +15 days for a solo parent; up to 30 more days without pay (RA 11210).
            $table->decimal('solo_parent_extra_days', 6, 2)->nullable()->after('max_days_per_filing');
            $table->decimal('unpaid_extension_days', 6, 2)->nullable()->after('solo_parent_extra_days');
            // How many times it can ever be taken (paternity: first four deliveries).
            $table->unsignedSmallInteger('max_filings')->nullable()->after('unpaid_extension_days');
            // A date the leave depends on, and how long after it the leave may be taken
            // (special emergency leave: within 30 days of the calamity).
            $table->string('event_label', 120)->nullable()->after('max_filings');
            $table->unsignedSmallInteger('event_window_days')->nullable()->after('event_label');
        });

        Schema::table('leave_applications', function (Blueprint $table) {
            $table->text('late_reason')->nullable()->after('remarks');
            $table->date('event_date')->nullable()->after('dates');
            // On approval, the credits each part was charged to, e.g. {"SL": 3, "VL": 2}.
            $table->json('charges')->nullable()->after('days_without_pay');
            // Leave of 30 calendar days or more: clearance from money, property and
            // work accountabilities (CSC MC No. 2, s. 1985).
            $table->string('clearance_path')->nullable()->after('attachment_size');
            $table->string('clearance_name')->nullable()->after('clearance_path');
            $table->string('clearance_mime', 100)->nullable()->after('clearance_name');
            $table->unsignedInteger('clearance_size')->nullable()->after('clearance_mime');
            // Sec. 49, Rule XVI: reminders sent as the five working days run out (0, 1 or 2).
            $table->unsignedTinyInteger('deadline_notices')->default(0)->after('current_step');
        });

        Schema::table('leave_monetizations', function (Blueprint $table) {
            // Terminal leave: HR confirms the clearance is on file (CSC MC No. 2, s. 1985).
            $table->boolean('clearance_on_file')->default(false)->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('leave_monetizations', fn (Blueprint $table) => $table->dropColumn('clearance_on_file'));
        Schema::table('leave_applications', fn (Blueprint $table) => $table->dropColumn([
            'late_reason', 'event_date', 'charges', 'clearance_path', 'clearance_name', 'clearance_mime', 'clearance_size', 'deadline_notices',
        ]));
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('excess_charged_to_id');
            $table->dropColumn([
                'late_filing_with_reason', 'excess_without_pay', 'medical_certificate_if_advance', 'requires_attachment',
                'solo_parent_extra_days', 'unpaid_extension_days', 'max_filings', 'event_label', 'event_window_days',
            ]);
        });
    }
};
