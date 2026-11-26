<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the late-November follow-ups to the 3.0.0-beta.2 What's New draft.
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
            ['improvement', 'My Leave Applications has a "Now with" column: who each application under review is waiting for and since when, or "You, to correct" when it was returned.'],
            ['improvement', 'HR pages have their own guides, opened from "How does this work?": verifying a PDS, deciding on record corrections, keeping the plantilla up to date, setting up leave types, holidays and officers-in-charge, and reviewing trainings in the L&D queue.'],
            ['improvement', 'Notes and reasons boxes grow as you type, so a long remark stays readable without scrolling inside a small box.'],
            ['fix', 'On your SALN, a child\'s date of birth now shows why it can\'t be saved (for example, 18 or older on the SALN date), and fields with a problem turn red so they are easy to find.'],
        ];
    }
};
