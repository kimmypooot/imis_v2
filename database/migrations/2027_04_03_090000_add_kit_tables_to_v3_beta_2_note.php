<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the rest of the admin tables moving onto the shared design system to the 3.0.0-beta.2 What's New draft
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
            ['improvement', 'More tables fit the page, with each person\'s photo and the same search, page size, Copy, CSV and Print: DTR Management, Work schedules, Biometric logs, Tardiness and undertime, Sync with the leave card file, Monetization, the payroll register and withholding tax, plantilla item holders, SALN statements, Integration Tokens and Security Logs.'],
            ['improvement', 'DTR Management searches and pages the list as you type, and Work schedules and Biometric logs tick rows to end, remove or delete them together.'],
        ];
    }
};
