<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Purge the demo-labeled placeholders the early phases seeded (Phase
     * 4/5 org units, Phase 6 services and categories, and the placeholder
     * description on the real systems). Matches only the exact demo
     * labels, so anything an admin created or renamed is left alone.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $unitIds = DB::table('organizational_units')
                ->where('name', 'like', '%(Demo)')
                ->pluck('id');

            DB::table('application_listings')
                ->where('listable_type', 'organizational_unit')
                ->whereIn('listable_id', $unitIds)
                ->delete();

            // parent_id restricts deleting a parent: detach any real unit
            // filed under a demo one, then delete demo children first.
            DB::table('organizational_units')
                ->whereIn('parent_id', $unitIds)
                ->whereNotIn('id', $unitIds)
                ->update(['parent_id' => null]);
            DB::table('organizational_units')
                ->whereIn('id', $unitIds)
                ->whereNotNull('parent_id')
                ->delete();
            DB::table('organizational_units')->whereIn('id', $unitIds)->delete();

            DB::table('services')
                ->whereIn('name', ['Sample Frontline Service (Demo)', 'Sample Certification Request (Demo)'])
                ->delete();
            DB::table('service_categories')
                ->whereIn('name', ['Frontline (Demo)', 'Certification (Demo)'])
                ->delete();

            DB::table('applications')
                ->where('description', 'Demonstration directory entry — link not yet configured.')
                ->update(['description' => null]);
        });
    }

    /**
     * Demo data is not restored.
     */
    public function down(): void
    {
        //
    }
};
