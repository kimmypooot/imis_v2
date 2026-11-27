<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notes office access managers (HR Staff for ORS and OTRS) in the
 * 3.0.0-beta.2 What's New draft. Nothing is added twice.
 */
return new class extends Migration
{
    private const VERSION = '3.0.0-beta.2';

    private const DESCRIPTION = 'HR Staff can grant roles in the systems HRD manages (ORS and OTRS) under System Access, for example giving Field Office staff the OTRS User role, without access to any other system. Who may do this is the new "Applications Manage Office Access" permission, which only ever covers the systems your own office manages.';

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
