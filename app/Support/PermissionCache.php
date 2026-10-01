<?php

namespace App\Support;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Invalidates the cached list of permission names that
 * AppServiceProvider uses to register Gate abilities.
 *
 * Call this whenever a permission is created, renamed, or deleted, so a
 * stale cache can never leave a new permission unenforceable or a removed
 * one still resolvable.
 */
class PermissionCache
{
    public static function flush(): void
    {
        Cache::forget(AppServiceProvider::PERMISSION_CACHE_KEY);
    }
}
