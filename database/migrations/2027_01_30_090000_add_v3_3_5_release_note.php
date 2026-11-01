<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.3.5 What's New release: richer account, leave and record pages, a
 * built-in User Guide, a clearer What's New and dashboard, and leave
 * calendars limited to your own division. Added already published, so it
 * is shown to everyone and becomes the version shown. Skipped when 3.3.5
 * already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.3.5';

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
            'title' => 'Richer pages, a built-in user guide and tighter leave calendars',
            'release_date' => '2026-11-02',
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
            ['improvement', 'A built-in User Guide: step-by-step help for everyday tasks, with search, a contents list that follows you as you read, a button to the real page for each task, and a print option. Open it from the new Help button in the header or the footer.'],
            ['improvement', 'Account pages redesigned: a clearer sidebar, a password strength meter with a live match check, on/off switches for email notifications, filters and reply bubbles on My Feedback, and a summary banner on Who Accessed My Record.'],
            ['improvement', 'Notifications are grouped by day, with an icon for each kind, relative times and an Unread filter.'],
            ['improvement', 'My Leave has a File leave button and shortcuts to your applications, the calendar, monetization and flexible work. My Leave Applications has summary tiles that filter the list.'],
            ['improvement', 'My Employee Record, My 201 File and My Service Record open with a strip of key facts.'],
            ['improvement', 'The dashboard has an At a glance row with your leave balances, leave in progress, approvals waiting for you and unread notifications, and the welcome banner now shows your division\'s full name.'],
            ['improvement', 'What\'s New is a timeline with the latest release highlighted, filters for New, Improved and Fixed, and older releases tucked behind a button.'],
            ['improvement', 'Leave calendars show only your own division\'s leave. The head of the Human Resource Division, a Director IV or III and a Super Administrator still see every office.'],
            ['improvement', 'The header names the system under the office name, the footer spans the full page width, printing a page leaves out the menus, and every page\'s breadcrumbs now start from Home.'],
        ];
    }
};
