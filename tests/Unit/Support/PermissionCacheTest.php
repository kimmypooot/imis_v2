<?php

namespace Tests\Unit\Support;

use App\Providers\AppServiceProvider;
use App\Support\PermissionCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PermissionCacheTest extends TestCase
{
    public function test_flush_removes_the_cached_permission_name_list(): void
    {
        Cache::forever(AppServiceProvider::PERMISSION_CACHE_KEY, ['users.view']);

        $this->assertTrue(Cache::has(AppServiceProvider::PERMISSION_CACHE_KEY));

        PermissionCache::flush();

        $this->assertFalse(Cache::has(AppServiceProvider::PERMISSION_CACHE_KEY));
    }
}
