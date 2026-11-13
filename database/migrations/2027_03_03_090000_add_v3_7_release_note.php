<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.7.0 What's New release: a visual refresh shared by every page (error pages, dialogs, buttons,
 * loading indicators, dashboards). Added already published, so it becomes the version shown. Skipped
 * when 3.7.0 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.7.0';

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
            'title' => 'A refreshed look: clearer error pages, dialogs and loading',
            'release_date' => '2026-11-14',
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
            ['feature', 'Error pages are redesigned: a clear status code, a plain explanation and the next step, such as Try again, Go back or Return to dashboard.'],
            ['feature', 'Dialogs have a solid blue header, and action buttons such as Add, Save, Edit, Delete and Export now carry an icon.'],
            ['improvement', 'A slimmer red bar and a new spinner show when a page or a button is working.'],
            ['improvement', 'Dashboard figures count up as the page opens, and menus, tabs and cards respond more smoothly. Everything still follows the reduced-motion setting on your device.'],
        ];
    }
};
