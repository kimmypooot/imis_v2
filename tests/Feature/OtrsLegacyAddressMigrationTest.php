<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The migration that points OTRS back at the legacy system.
 */
class OtrsLegacyAddressMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Migrations register real hub systems; these tests count their own.
        Application::query()->delete();
    }

    private function migrate(): void
    {
        (require database_path('migrations/2027_01_25_090000_point_otrs_at_the_legacy_system.php'))->up();
    }

    private function otrs(?string $url): Application
    {
        return Application::factory()->create(['short_name' => 'OTRS', 'url' => $url]);
    }

    public function test_otrs_pointing_at_the_laravel_app_goes_back_to_the_legacy_system(): void
    {
        $otrs = $this->otrs('http://localhost:8020/sso');
        $admin = ApplicationRole::factory()->for($otrs)->create(['name' => 'Admin', 'code' => 'admin']);
        $user = ApplicationRole::factory()->for($otrs)->create(['name' => 'User', 'code' => 'field-office']);

        $this->migrate();

        $this->assertSame('https://imis.cscro8.com/otrs/index', $otrs->fresh()->url);
        $this->assertSame('https://imis.cscro8.com/otrs/hrd/index', $admin->fresh()->launch_url);
        $this->assertNull($user->fresh()->launch_url);
    }

    public function test_an_otrs_without_an_address_gets_the_legacy_one(): void
    {
        $otrs = $this->otrs(null);

        $this->migrate();

        $this->assertSame('https://imis.cscro8.com/otrs/index', $otrs->fresh()->url);
    }

    public function test_a_legacy_style_address_is_left_alone(): void
    {
        $otrs = $this->otrs('https://other.example.test/otrs/');
        $admin = ApplicationRole::factory()->for($otrs)->create(['name' => 'Admin', 'code' => 'admin']);

        $this->migrate();

        $this->assertSame('https://other.example.test/otrs/', $otrs->fresh()->url);
        $this->assertNull($admin->fresh()->launch_url);
    }

    public function test_an_admin_role_with_its_own_launch_address_keeps_it(): void
    {
        $otrs = $this->otrs('http://localhost:8020/sso');
        $admin = ApplicationRole::factory()->for($otrs)->create(['name' => 'Admin', 'code' => 'admin', 'launch_url' => 'https://own.example.test/start']);

        $this->migrate();

        $this->assertSame('https://own.example.test/start', $admin->fresh()->launch_url);
    }

    public function test_it_does_nothing_without_an_otrs_entry(): void
    {
        $this->migrate();

        $this->assertSame(0, Application::query()->count());
    }
}
