<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.5.2 What's New release: a redesigned look and richer wording for the emails IMIS sends, and
 * a live preview in Admin → Email Templates. Added already published, so it becomes the
 * version shown. Skipped when 3.5.2 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.5.2';

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
            'title' => 'Modern, richer emails and a live email preview',
            'release_date' => '2026-11-11',
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
            ['feature', 'Every IMIS email has a new look: the CSC logo and system name, a clear button, a link to copy if the button fails, and a tidy footer.'],
            ['feature', 'Account setup, password reset and the weekly summary now show key details, such as your username and how long the link is valid, in an easy-to-read panel.'],
            ['improvement', 'The wording of all emails is warmer and clearer, and the weekly summary lists systems needing attention and off-target figures in a table.'],
            ['feature', 'Admin → Email Templates now shows each email exactly as it will arrive, in Desktop or Phone view, updating as you type, with character counters, placeholder hints and an unsaved-changes marker.'],
        ];
    }
};
