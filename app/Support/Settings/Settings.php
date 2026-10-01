<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Cached, typed read access to settings. All values are loaded with one
 * query and cached forever; App\Models\Setting flushes the cache after any
 * save or delete commits, so a changed value is visible on the very next
 * request.
 *
 * A setting with no row (not seeded yet) or a null value falls back to its
 * default in SettingRegistry.
 */
class Settings
{
    public const CACHE_KEY = 'settings.values';

    public static function get(string $key): string|int|bool|null
    {
        return self::all()[$key] ?? SettingRegistry::default($key);
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public static function all(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        // Before migrations have run (fresh install), every setting reads
        // as its default — and that empty result is not cached.
        if (! Schema::hasTable('settings')) {
            return [];
        }

        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::query()
                ->get(['key', 'value', 'type'])
                ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->typedValue()])
                ->all(),
        );
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
