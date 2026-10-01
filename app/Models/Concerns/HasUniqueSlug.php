<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Shared slug generation for models with a `slug` column. Called
 * explicitly by whatever creates a record (controller, seeder) —
 * deliberately not a `creating` model event, since this project's
 * `DatabaseSeeder` uses `WithoutModelEvents` and would silently skip an
 * event-based hook, leaving the slug blank (see OrganizationalUnit,
 * Phase 5, where this was first discovered).
 */
trait HasUniqueSlug
{
    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
