<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Support\Settings\Settings;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The login page only offers saved-account cards while the Security
 * setting allows it; the cards themselves live in the browser.
 */
class RememberedAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_offers_saved_accounts_by_default(): void
    {
        $this->seed(SettingSeeder::class);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('rememberAccounts', true));
    }

    public function test_administrators_can_turn_saved_accounts_off(): void
    {
        $this->seed(SettingSeeder::class);
        Setting::query()->where('key', 'security_remember_accounts')->update(['value' => '0']);
        Settings::flush();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('rememberAccounts', false));
    }
}
