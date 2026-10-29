<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.2.0 What's New release: a fresher look, a redesigned loading screen,
 * optional committee start dates and the employee list for ICT SRTS. Added as
 * a draft, so an admin reviews it in Admin → Changelogs and publishes it
 * (publishing shows it to everyone and makes 3.2.0 the version shown).
 * Skipped when 3.2.0 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.2.0';

    public function up(): void
    {
        if (DB::table('changelogs')->where('version', self::VERSION)->exists()) {
            return;
        }

        DB::transaction(function () {
            $this->insert();
        });
    }

    private function insert(): void
    {
        $now = now();
        $id = DB::table('changelogs')->insertGetId([
            'version' => self::VERSION,
            'title' => 'A fresher look and a calmer loading screen',
            'release_date' => '2026-10-29',
            'status' => 'draft',
            'published_at' => null,
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
            ['improvement', 'A fresher look throughout: buttons that respond when you press them, cards and form fields that react when you point at them, accent bars on page headings, striped tables with a highlighted row, and gradient avatars and welcome banner.'],
            ['improvement', 'A redesigned loading screen: the full name of IMIS under the agency name, one calm pulsing ring, and a sweeping bar instead of a progress bar that pretended to know how long the wait would be. It fits small screens and Windows high-contrast mode.'],
            ['improvement', 'Committee members no longer need a start date: leave it empty when it is not known, and the committee page shows "No start date".'],
            ['improvement', 'The ICT Service Request Ticketing System can now read the current employee list, and who is on the Information Technology Group committee, from IMIS, so its signatories and ITG members stay in step with IMIS.'],
        ];
    }
};
