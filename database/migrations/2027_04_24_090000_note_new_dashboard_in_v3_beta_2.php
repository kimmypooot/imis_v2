<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's new command-center layout in the 3.0.0-beta.2 What's New
 * draft: its entry on the dashboard is rewritten to describe the new page,
 * and the entry on the old Calendar of Activities section's Hide button is
 * dropped, since that section is gone (its month calendar and the coming
 * week are now "Coming up" in the side column). Only a draft is touched: a
 * published release keeps its notes. Nothing is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    private const OLD_ENTRY = 'The dashboard has an At a glance row%';

    private const HIDE_BUTTON_ENTRY = 'The Calendar of Activities on your dashboard now has a Hide button%';

    // At most 500 characters (changelog_items.description).
    private const ENTRY = 'A new dashboard: your figures in one strip at the top; "Needs your attention", listing approvals waiting for you, last month\'s DTR to sign and anything returned for correction, each with a button to it; the Systems Directory three across, with your role on each system; and beside them what\'s coming up (a month calendar and the next 7 days), announcements and system status. Quick access in the banner opens File Leave, My DTR, My Payslip, My PDS and the Calendar.';

    public function up(): void
    {
        $changelog = DB::table('changelogs')->where('version', self::VERSION)->where('status', 'draft')->first();

        if (! $changelog) {
            return;
        }

        DB::transaction(function () use ($changelog) {
            $items = DB::table('changelog_items')->where('changelog_id', $changelog->id);

            (clone $items)->where('description', 'like', self::HIDE_BUTTON_ENTRY)->delete();

            if ((clone $items)->where('description', self::ENTRY)->doesntExist()) {
                $updated = (clone $items)->where('description', 'like', self::OLD_ENTRY)->update(['description' => self::ENTRY]);

                if (! $updated) {
                    DB::table('changelog_items')->insert([
                        'changelog_id' => $changelog->id,
                        'type' => 'improvement',
                        'description' => self::ENTRY,
                        'sort_order' => (int) (clone $items)->max('sort_order') + 1,
                    ]);
                }
            }

            DB::table('changelogs')->where('id', $changelog->id)->update(['updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // The earlier wording is not restored; the draft can still be edited under Admin → Changelog.
    }
};
