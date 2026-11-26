<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the PDS printed on the official CS Form No. 212 to the 3.0.0-beta.2
 * What's New draft.
 * Runs after curate_v3_beta_2_note, which replaces every earlier entry, so
 * these are appended to the curated list. Nothing is added twice.
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
            ['improvement', "The PDS prints on the official CS Form No. 212 (Revised 2026) itself: CSC's own pages as Excel prints them on 8.5 × 13 in paper, with your entries in their boxes and the boxes ticked, and continuation sheets (C5–C11) for lists that don't fit. \"Download Excel\" gives the same PDS on CSC's own Excel form, photo included."],
        ];
    }
};
