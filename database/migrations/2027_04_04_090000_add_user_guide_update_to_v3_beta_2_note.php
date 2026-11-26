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
            ['improvement', 'The User Guide covers what IMIS does now: your DTR (missed punches, choosing a verifier, signing), your SALN, record corrections, your e-signature, leave monetization, flexible work, downloading your data, dark mode and installing IMIS as an app, plus how to search and export any list.'],
            ['improvement', 'Supervisors, the head of HRD, Accounting, the SALN Committee and HR staff each see guides for their own work: verifying DTR entries, approving payslips, entering withholding tax, reviewing SALNs, and preparing DTRs, payroll, SALNs and NOSA/NOSI.'],
        ];
    }
};
