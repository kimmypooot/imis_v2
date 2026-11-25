<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the Age & Service page to the 3.0.0-beta.2 What's New draft
 * (created by fold_dev_releases_into_v3_betas, which runs first). Nothing
 * is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->first();

        if (! $changelog) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            $existing = DB::table('changelog_items')->where('changelog_id', $changelog->id)->pluck('description')->all();
            $next = (int) DB::table('changelog_items')->where('changelog_id', $changelog->id)->max('sort_order') + 1;

            foreach (self::items() as [$type, $description]) {
                if (in_array($description, $existing, true)) {
                    continue;
                }

                DB::table('changelog_items')->insert([
                    'changelog_id' => $changelog->id,
                    'type' => $type,
                    'description' => $description,
                    'sort_order' => $next++,
                ]);
            }

            DB::table('changelogs')->where('id', $changelog->id)->update(['updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // A published release keeps its items.
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function items(): array
    {
        return [
            ['feature', 'Age & Service (Human Resources): everyone in service with their age, length of service in government and in the CSC, retirement dates at 60 and 65, and next loyalty award. Filter by office or show birthdays, upcoming retirees or this year\'s loyalty awards; the HR dashboard\'s cards link to it.'],
            ['fix', 'A one-day leave on the HR dashboard shows its date once instead of as a range.'],
        ];
    }
};
