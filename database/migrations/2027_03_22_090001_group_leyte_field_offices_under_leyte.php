<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The plantilla has one item for the Leyte field office, but IMIS keeps two
 * working offices (Leyte I and Leyte II). A parent office "CSC Field Office -
 * Leyte" ties them together: plantilla items belong to the parent, while
 * staff, leave routing and DTR stay with the office they work in.
 * Idempotent: skipped parts are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $children = DB::table('organizational_units')->whereIn('code', ['LFO-I', 'LFO-II'])->get();

        if ($children->isEmpty()) {
            return;
        }

        $parent = DB::table('organizational_units')->where('code', 'LFO')->first();

        if (! $parent) {
            $now = now();
            $parentId = DB::table('organizational_units')->insertGetId([
                'code' => 'LFO',
                'name' => 'CSC Field Office - Leyte',
                'short_name' => 'LFO',
                'slug' => $this->uniqueSlug('csc-field-office-leyte'),
                'unit_type' => 'field_office',
                'status' => 'active',
                'sort_order' => (int) $children->min('sort_order'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $parentId = $parent->id;
        }

        DB::table('organizational_units')
            ->whereIn('id', $children->pluck('id'))
            ->whereNull('parent_id')
            ->update(['parent_id' => $parentId, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $parent = DB::table('organizational_units')->where('code', 'LFO')->first();

        if (! $parent) {
            return;
        }

        DB::table('organizational_units')->where('parent_id', $parent->id)->update(['parent_id' => null]);

        if (! DB::table('plantilla_items')->where('organizational_unit_id', $parent->id)->exists()
            && ! DB::table('users')->where('organizational_unit_id', $parent->id)->exists()) {
            DB::table('organizational_units')->where('id', $parent->id)->delete();
        }
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $n = 2;

        while (DB::table('organizational_units')->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
};
