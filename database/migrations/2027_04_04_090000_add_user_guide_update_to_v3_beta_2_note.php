<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the refreshed User Guide to the 3.0.0-beta.2 What's New draft
 * (created by fold_dev_releases_into_v3_betas, which runs first). Nothing
 * is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->first();

        if (! $changelog) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            $existing = DB::table('changelog_items')->where('changelog_id', $changelog->id)->pluck('description')->all();
            $next = (int) DB::table('changelog_items')->where('changelog_id', $changelog->id)->max('sort_order') + 1;

            foreach (self::items() as [$type, $description]) {
                if (in_array($description, $existing, true)) {
                    continue;
                }

                DB::table('changelog_items')->insert([
                    'changelog_id' => $changelog->id,
                    'type' => $type,
                    'description' => $description,
                    'sort_order' => $next++,
                ]);
            }

            DB::table('changelogs')->where('id', $changelog->id)->update(['updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // A published release keeps its items.
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function items(): array
    {
        return [
            ['improvement', 'Switch areas in one click: the top of the side navigation now offers Me (your own records, leave and pay), HR and Admin, each shown only if you have access. HRD staff move between their own pages and HR work without scrolling to the bottom of the menu.'],
            ['improvement', 'The home page\'s Notifications tile, which repeated the bell, is now "Late & undertime": this month\'s total so far, and a reminder when last month\'s DTR is waiting for your signature.'],
            ['improvement', 'Staff pages match the rest of IMIS: the filter tiles on My Leave Applications and My Feedback, the filter chips on Notifications, What\'s New, Payslip Approvals and the training log, and the leave card\'s view switch all look and work the same way.'],
            ['improvement', 'DTR entries to verify is a table with each person\'s photo: tick several days and verify them together. Payslip Approvals shows each requester\'s photo.'],
            ['improvement', 'The User Guide covers what IMIS does now: your DTR (missed punches, choosing a verifier, signing), your SALN, record corrections, your e-signature, leave monetization, flexible work, downloading your data, dark mode and installing IMIS as an app, plus how to search and export any list.'],
            ['improvement', 'Supervisors, the head of HRD, Accounting, the SALN Committee and HR staff each see guides for their own work: verifying DTR entries, approving payslips, entering withholding tax, reviewing SALNs, and preparing DTRs, payroll, SALNs and NOSA/NOSI.'],
        ];
    }
};
