<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notes the 2027 holidays (Proclamation No. 1427, s. 2026) in the
 * 3.0.0-beta.2 What's New draft. Nothing is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    private const DESCRIPTION = 'The 2027 holidays from Proclamation No. 1427, s. 2026 are in Holidays: 10 regular holidays, 8 special non-working days and the EDSA anniversary as a special working day, so leave days filed for 2027 are counted correctly. Eid\'l Fitr and Eid\'l Adha are added once they are proclaimed.';

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
