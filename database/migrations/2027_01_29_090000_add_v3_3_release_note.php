<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.3.0 What's New release: a refreshed sign-in page, saved-account
 * cards and a redesigned privacy notice. Added already published, so it is
 * shown to everyone and becomes the version shown. Skipped when 3.3.0 already
 * exists.
 */
return new class extends Migration
{
    private const VERSION = '3.3.0';

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
            'title' => 'A refreshed sign-in page and a clearer privacy notice',
            'release_date' => '2026-10-31',
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
            ['improvement', 'A refreshed sign-in page: a flat red accent on the sign-in card, a clearer description of IMIS as the centralized digital hub across the Regional Support Units, CSC Field Offices and Satellite Offices, a note that it is for authorized CSC personnel only, and highlights that stay tucked away until you open them.'],
            ['improvement', 'The sign-in button now turns red while you are being signed in, instead of staying blue.'],
            ['feature', 'Saved-account cards on the sign-in page: pick a card with your name and photo instead of typing your username again. Passwords are never saved, and an administrator can turn this off in System Settings for shared computers.'],
            ['improvement', 'A redesigned privacy notice: a contents list that jumps to each section, a card with an icon for every section, and a clearer contact block for questions and requests.'],
        ];
    }
};
