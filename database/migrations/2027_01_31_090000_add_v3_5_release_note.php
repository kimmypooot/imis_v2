<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.5.0 What's New release: security hardening from the audit, steadier
 * dialogs and motion, and employee and user feeds for connected systems.
 * Added already published, so it is shown to everyone and becomes the
 * version shown. Skipped when 3.5.0 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.5.0';

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
            'title' => 'Stronger security, steadier dialogs and new feeds for connected systems',
            'release_date' => '2026-11-05',
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
            ['improvement', 'Security hardening: browsers are now told to apply stricter protections to every page, and repeated failed sign-ins to one account are limited no matter where they come from.'],
            ['improvement', 'Dialogs keep the keyboard focus inside them while open, and animations are reduced for people whose device asks for less motion.'],
            ['improvement', 'Connected systems can now read employee and user lists from IMIS: the Recruitment Portal gets users and an employee\'s verified PDS and 201 documents, and the ETTS gets an employees feed.'],
        ];
    }
};
