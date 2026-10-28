<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The migration that adds TIMS beside OTRS and gives it OTRS's people.
 */
class TimsSystemMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2027_01_24_090000_add_training_information_management_system.php'))->up();
    }

    private function otrs(): Application
    {
        return Application::factory()->create(['short_name' => 'OTRS', 'url' => 'https://legacy.example.test/otrs']);
    }

    private function role(Application $system, string $name, ?string $code): ApplicationRole
    {
        return ApplicationRole::factory()->for($system)->create(['name' => $name, 'code' => $code]);
    }

    private function tims(): Application
    {
        return Application::query()->where('short_name', 'TIMS')->firstOrFail();
    }

    public function test_tims_is_added_with_its_five_roles_and_no_url(): void
    {
        $this->otrs();

        $this->migrate();

        $tims = $this->tims();
        $this->assertNull($tims->url);
        $this->assertTrue($tims->requires_access);
        $this->assertEqualsCanonicalizing(
            ['admin', 'management', 'field-office', 'collecting-officer', 'superadmin'],
            $tims->roles()->pluck('code')->all(),
        );
    }

    public function test_otrs_access_is_copied_by_role_code_and_by_name_for_roles_without_a_code(): void
    {
        $otrs = $this->otrs();
        $admin = ApplicationAccess::factory()->forRole($this->role($otrs, 'Admin', 'admin'))->create()->user;
        $user = ApplicationAccess::factory()->forRole($this->role($otrs, 'User', null))->create()->user;

        $this->migrate();

        $grants = $this->tims()->accessGrants()->with('role')->get()->keyBy('user_id');
        $this->assertSame('admin', $grants[$admin->id]->role->code);
        $this->assertSame('field-office', $grants[$user->id]->role->code);
        $this->assertSame(2, ApplicationAccess::query()->where('application_id', $otrs->id)->count());
    }

    public function test_inactive_accounts_and_designation_grants_are_left_out(): void
    {
        $otrs = $this->otrs();
        $role = $this->role($otrs, 'Admin', 'admin');
        $inactive = ApplicationAccess::factory()->forRole($role)->create()->user;
        $inactive->update(['status' => UserStatus::Inactive]);
        $designated = ApplicationAccess::factory()->forRole($role)->create();
        $designated->forceFill(['via_designation' => true])->save();
        $kept = ApplicationAccess::factory()->forRole($role)->create()->user;

        $this->migrate();

        $this->assertSame([$kept->id], $this->tims()->accessGrants()->pluck('user_id')->all());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $otrs = $this->otrs();
        ApplicationAccess::factory()->forRole($this->role($otrs, 'Admin', 'admin'))->create();

        $this->migrate();
        $this->migrate();

        $this->assertSame(1, Application::query()->where('short_name', 'TIMS')->count());
        $this->assertSame(1, $this->tims()->accessGrants()->count());
    }

    public function test_it_adds_nothing_when_there_is_no_otrs(): void
    {
        $this->migrate();

        $this->assertSame(0, Application::query()->where('short_name', 'TIMS')->count());
    }
}
