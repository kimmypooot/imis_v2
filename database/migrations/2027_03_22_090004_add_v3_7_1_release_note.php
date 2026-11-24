<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.7.1 What's New release: the Leyte field office as one place of
 * assignment over Leyte I and II, shorter office labels, and a Head capacity
 * for designations. Added already published, so it becomes the version
 * shown. Skipped when 3.7.1 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.7.1';

    public function up(): void
    {
        if (DB::table('changelogs')->where('version', self::VERSION)->exists()) {
            return;
        }

        DB::transaction(function () {
            $now = now();
            $id = DB::table('changelogs')->insertGetId([
                'version' => self::VERSION,
                'title' => 'One Leyte field office for plantilla, shorter office labels and a Head capacity',
                'release_date' => '2026-11-24',
                'status' => 'published',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('changelog_items')->insert(array_map(
                fn (array $item, int $index) => [
                    'changelog_id' => $id,
                    'type' => $item[0],
                    'description' => $item[1],
                    'sort_order' => $index,
                ],
                self::items(),
                array_keys(self::items()),
            ));
        });
    }

    public function down(): void
    {
        // Only an unpublished draft is removed; a published release stays.
        DB::table('changelogs')->where('version', self::VERSION)->where('status', 'draft')->delete();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function items(): array
    {
        return [
            ['feature', 'The plantilla now has one "CSC Field Office - Leyte" place of assignment over Leyte I and Leyte II. It appears only when choosing a plantilla item\'s place of assignment; people still work in, and are listed under, Leyte I or Leyte II.'],
            ['feature', 'Designations have a new capacity, "Head (same place of assignment)", for a head whose second office is part of their one place of assignment. It heads the office like an Officer-in-Charge or Caretaker but is not shown as concurrent.'],
            ['improvement', 'Field offices use shorter labels (FO-Leyte I, FO-Leyte II, FO-SL, SO-WL, FO-B, FO-S, FO-ES, FO-NS). Office codes are unchanged, so connected systems are not affected.'],
            ['improvement', 'The head of an office that groups others now sees the records of the offices under it.'],
            ['improvement', 'Importing the plantilla workbook now recognizes the "FO No. 6 Leyte" heading.'],
            ['fix', 'Appointing someone to an item whose place of assignment is plantilla-only no longer moves their account out of the office they work in.'],
        ];
    }
};
