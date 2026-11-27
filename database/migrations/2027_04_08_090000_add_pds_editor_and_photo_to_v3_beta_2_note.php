<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the PDS editor, the guide's filling rules, the passport-size photo
 * and the CS Form No. 6 download fix to the 3.0.0-beta.2 What's New draft.
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
            ['improvement', 'Filling in your PDS goes part by part: the parts of the form are listed beside it with how far along each is, and each part has one Save and "Save and continue". Lists show one line per entry (work experience newest first, with its dates in their own column), opening to the full form only for the one you are editing; children and items 31–33 are typed straight in, one per line.'],
            ['improvement', 'The PDS follows CSC\'s Guide to Filling Out the PDS: N/A in anything not applicable, "If YES" details only for a Yes, work experience from your current position down, salary as ₱21,877.00 and pay grade as "00-0". The fields give the guide\'s tips, e.g. PRIMARY EDUCATION, or JUNIOR or SENIOR HIGH SCHOOL.'],
            ['improvement', 'The PDS photo must be exactly passport size, 413 × 531 pixels (3.5 × 4.5 cm), so it prints at its true size over the photo frame. A picture of any other size opens a cropper where you place your face and zoom, and it is saved at exactly that size.'],
            ['fix', 'Downloading an application for leave (CS Form No. 6) as Excel works again; it had failed with an error.'],
        ];
    }
};
