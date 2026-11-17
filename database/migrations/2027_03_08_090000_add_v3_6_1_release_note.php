<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.6.1 What's New release: small improvements for administrators (profile photo cropping, a
 * default role, Active/Inactive tabs). Added already published, so it becomes the version shown.
 * Skipped when 3.6.1 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.6.1';

    public function up(): void
    {
        if (DB::table('changelogs')->where('version', self::VERSION)->exists()) {
            return;
        }

        DB::transaction(function () {
            $now = now();
            $id = DB::table('changelogs')->insertGetId([
                'version' => self::VERSION,
                'title' => 'Photo cropping, a default role and Active/Inactive tabs',
                'release_date' => '2026-11-17',
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
            ['improvement', 'Choosing a profile photo for a user now opens a cropper: drag the photo to position it and zoom, and the circle shows what the avatar will display.'],
            ['improvement', 'Every account now has an IMIS role. New accounts start with the User role, and roles can no longer be cleared to nothing.'],
            ['improvement', 'Manage Systems, Committees, Offices, Service Categories and Services now have Active and Inactive tabs, like Manage Users.'],
            ['fix', 'Updating or removing a user\'s profile photo now always shows a confirmation message.'],
            ['fix', 'Super Administrators now always see the buttons for newly added features, such as deleting a system and Integration Tokens.'],
        ];
    }
};
