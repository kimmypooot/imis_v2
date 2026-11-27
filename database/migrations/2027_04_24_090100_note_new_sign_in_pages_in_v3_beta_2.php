<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The redesigned sign-in pages in the 3.0.0-beta.2 What's New draft. Only a
 * draft is touched: a published release keeps its notes. Nothing is added
 * twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    // At most 500 characters (changelog_items.description).
    private const ENTRY = 'A new look for the sign-in pages: the CSC RO VIII panel sits beside your sign-in card on a light page, with the version you are on at its foot, and the login, forgot password, reset password and verify email pages share one clear heading. The login page says "Sign in" throughout.';

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->where('status', 'draft')->first();

        if (! $changelog) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            $items = DB::table('changelog_items')->where('changelog_id', $changelog->id);

            if ((clone $items)->where('description', self::ENTRY)->exists()) {
                return;
            }

            DB::table('changelog_items')->insert([
                'changelog_id' => $changelog->id,
                'type' => 'improvement',
                'description' => self::ENTRY,
                'sort_order' => (int) (clone $items)->max('sort_order') + 1,
            ]);

            DB::table('changelogs')->where('id', $changelog->id)->update(['updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // A published release keeps its items; the draft can still be edited under Admin → Changelog.
    }
};
