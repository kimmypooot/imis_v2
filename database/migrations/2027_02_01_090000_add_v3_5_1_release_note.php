<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.5.1 What's New release: small improvements to the sign-out dialog and
 * the Organizational Units page. Added already published, so it becomes the
 * version shown. Skipped when 3.5.1 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.5.1';

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
            'title' => 'Quicker sign-out and a tidier Organizational Units page',
            'release_date' => '2026-11-10',
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
            ['improvement', 'The sign-out dialog now has "Sign out" selected by default, so pressing Enter signs you out.'],
            ['improvement', 'The Organizational Units page and office directory are tidier and more accurate.'],
        ];
    }
};
