<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the DTR items to the v3.7.1 What's New release where it
 * already exists (databases that ran add_v3_7_1_release_note before these were written). A fresh
 * database gets them here too, since that migration runs first; nothing is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.7.1';

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
            ['feature', 'Biometric logs: HRD uploads the consolidated .dat file once a month, every employee is told under the bell who uploaded it, and a monitor shows which offices have submitted. The Regional Support Units count as one office; each field office is separate.'],
            ['feature', 'DTR to Excel: export CS Form 48 as an Excel file, for your own DTR, for any employee (HRD), or for a whole office for one month.'],
            ['feature', 'Enter your own times: on My DTR, choose Edit my entries to fill in a missing or wrong time with a reason, then certify that it is true and correct. Your fingerprint punches are never changed.'],
            ['feature', 'Verification: your supervisor or office head is told when you enter times and verifies them under DTR Entries to Verify. HRD can verify as a fallback. A month already charged to leave credits is closed to your own entries.'],
            ['feature', 'Tardiness and undertime: HRD sees the days employees entered themselves, can charge all reviewed rows at once, and must verify entered days (or choose to charge anyway, which is logged) before charging. Possible habitual tardiness and absenteeism are flagged.'],
            ['improvement', 'On My DTR, an absent day offers a link to file leave for that day. Work Schedules and Biometric Logs have clearer layouts, and employees can be removed from a work schedule in bulk.'],
        ];
    }
};
