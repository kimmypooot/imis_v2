<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notes that offices and the plantilla follow the regional office order in the
 * 3.0.0-beta.2 What's New draft. Nothing is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    private const DESCRIPTION = 'Office lists follow the order set in Offices (Office of the Regional Director first), and the plantilla lists items by office, then highest salary grade, then item number (the plantilla sheet follows the same order). The Western Leyte Satellite Office is set up under the Biliran Field Office.';

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
                'type' => 'improvement',
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
