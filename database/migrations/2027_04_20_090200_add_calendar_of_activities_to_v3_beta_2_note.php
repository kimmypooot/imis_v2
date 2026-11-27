<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notes the Calendar of Activities and the dashboard banner's quick access
 * in the 3.0.0-beta.2 What's New draft. Nothing is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    /** @var array<int, array{type: string, description: string}> */
    private const ITEMS = [
        [
            'type' => 'feature',
            'description' => 'The Calendar of Activities: every office posts its activities and PALD keeps the regional calendar in order. See it as a month calendar or as the monthly sheet, print or download "CSC RO VIII CALENDAR OF ACTIVITIES" for any month, and see the coming week on your dashboard.',
        ],
        [
            'type' => 'improvement',
            'description' => 'The dashboard\'s welcome banner has quick access to File Leave, My DTR, My Payslip, My PDS and the Calendar of Activities.',
        ],
    ];

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->first();

        if (! $changelog) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            $added = false;

            foreach (self::ITEMS as $item) {
                $exists = DB::table('changelog_items')
                    ->where('changelog_id', $changelog->id)
                    ->where('description', $item['description'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('changelog_items')->insert([
                    'changelog_id' => $changelog->id,
                    'type' => $item['type'],
                    'description' => $item['description'],
                    'sort_order' => (int) DB::table('changelog_items')->where('changelog_id', $changelog->id)->max('sort_order') + 1,
                ]);

                $added = true;
            }

            if ($added) {
                DB::table('changelogs')->where('id', $changelog->id)->update(['updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // A published release keeps its items.
    }
};
