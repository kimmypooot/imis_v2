<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notes repeating holidays in the 3.0.0-beta.2 What's New draft. Nothing
 * is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    private const DESCRIPTION = 'Holidays that fall on the same date every year (New Year\'s Day, Independence Day, Christmas Day…) are entered once by month and day and count in every year, shown under "Every year" without a year. Movable ones such as Holy Week and National Heroes Day are still entered for each year, and a dated holiday of the same name moves a repeating one for that year only.';

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->first();

        if (! $changelog) {
            return;
        }

        $exists = DB::table('changelog_items')
            ->where('changelog_id', $changelog->id)
            ->where('description', self::DESCRIPTION)
            ->exists();

        if ($exists) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            DB::table('changelog_items')->insert([
                'changelog_id' => $changelog->id,
                'type' => 'feature',
                'description' => self::DESCRIPTION,
                'sort_order' => (int) DB::table('changelog_items')->where('changelog_id', $changelog->id)->max('sort_order') + 1,
            ]);

            DB::table('changelogs')->where('id', $changelog->id)->update(['updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // A published release keeps its items.
    }
};
