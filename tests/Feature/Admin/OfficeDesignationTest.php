<?php

namespace Tests\Feature\Admin;

use App\Enums\OfficeDesignation;
use App\Enums\UnitType;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Division Chief (RSU) / Field Director (field & satellite office): overall
 * admin of the office's systems, granted and taken back automatically.
 */
class OfficeDesignationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OrganizationalUnit $msd;

    private OrganizationalUnit $fieldOffice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithPermissions('users.view', 'users.update', 'users.create', 'users.delete', 'applications.manage_access', 'applications.update');
        $this->msd = OrganizationalUnit::factory()->create(['name' => 'Management Services Division', 'unit_type' => UnitType::RegionalSupportUnit]);
        $this->fieldOffice = OrganizationalUnit::factory()->create(['name' => 'CSC Field Office - Leyte I', 'unit_type' => UnitType::FieldOffice]);
    }

    public function test_a_division_chief_becomes_admin_of_the_divisions_systems(): void
    {
        $dv = $this->system($this->msd, ['Admin', 'User']);
        $cts = $this->system($this->msd, ['User', 'admin', 'Superadmin']);
        $listed = $this->system(null, ['Admin']);
        $listed->listedUnits()->attach($this->msd);
        $noAdmin = $this->system($this->msd, ['User']);
        $elsewhere = $this->system(OrganizationalUnit::factory()->create(['unit_type' => UnitType::RegionalSupportUnit]), ['Admin']);
        $chief = User::factory()->create(['organizational_unit_id' => $this->msd->id]);

        $this->actingAs($this->admin)->put("/users/{$chief->id}", $this->profile($chief, 'division_chief'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'granted admin in 3 systems'));

        $this->assertSame(OfficeDesignation::DivisionChief, $chief->fresh()->office_designation);
        $this->assertSame('Admin', $this->roleIn($chief, $dv));
        // The strongest admin role wins.
        $this->assertSame('Superadmin', $this->roleIn($chief, $cts));
        $this->assertSame('Admin', $this->roleIn($chief, $listed));
        $this->assertNull($this->roleIn($chief, $noAdmin));
        $this->assertNull($this->roleIn($chief, $elsewhere));
        $this->assertTrue(ApplicationAccess::query()->where('user_id', $chief->id)->get()->every->via_designation);
        $this->assertDatabaseHas('audit_logs', ['event' => 'application.access_granted', 'target_id' => $dv->id]);
    }

    public function test_removing_it_undoes_only_what_it_did(): void
    {
        $raised = $this->system($this->fieldOffice, ['Admin', 'User']);
        $created = $this->system($this->fieldOffice, ['Admin']);
        $manualAdmin = $this->system($this->fieldOffice, ['Admin']);
        $director = User::factory()->create(['organizational_unit_id' => $this->fieldOffice->id]);
        $this->grant($director, $raised, 'User');
        $this->grant($director, $manualAdmin, 'Admin');

        $this->actingAs($this->admin)->put("/users/{$director->id}", $this->profile($director, 'field_director'))->assertSessionHasNoErrors();
        $this->assertSame('Admin', $this->roleIn($director, $raised));
        $this->assertSame('Admin', $this->roleIn($director, $created));

        $this->actingAs($this->admin)->put("/users/{$director->id}", $this->profile($director, null))->assertSessionHasNoErrors();

        $this->assertNull($director->fresh()->office_designation);
        $this->assertSame('User', $this->roleIn($director, $raised));
        $this->assertNull($this->roleIn($director, $created));
        // An admin's own grant stays.
        $this->assertSame('Admin', $this->roleIn($director, $manualAdmin));
        $this->assertSame(0, ApplicationAccess::query()->where('via_designation', true)->count());
    }

    public function test_it_must_fit_the_office_be_unique_and_be_set_by_someone_who_may_grant_access(): void
    {
        $user = User::factory()->create(['organizational_unit_id' => $this->fieldOffice->id]);

        $this->actingAs($this->admin)->put("/users/{$user->id}", $this->profile($user, 'division_chief'))
            ->assertSessionHasErrors('office_designation');

        User::factory()->create(['organizational_unit_id' => $this->fieldOffice->id, 'office_designation' => 'field_director']);
        $this->actingAs($this->admin)->put("/users/{$user->id}", $this->profile($user, 'field_director'))
            ->assertSessionHasErrors('office_designation');

        // users.update alone isn't enough: it hands out admin access.
        $editor = $this->userWithPermissions('users.view', 'users.update');
        $other = User::factory()->create(['organizational_unit_id' => $this->msd->id]);
        $this->actingAs($editor)->put("/users/{$other->id}", $this->profile($other, 'division_chief'))
            ->assertSessionHasErrors('office_designation');

        // Nor on yourself.
        $this->admin->update(['organizational_unit_id' => $this->msd->id]);
        $this->actingAs($this->admin)->put("/users/{$this->admin->id}", $this->profile($this->admin, 'division_chief'))
            ->assertSessionHasErrors('office_designation');

        // Other edits don't need it, and leave the designation alone.
        $chief = User::factory()->create(['organizational_unit_id' => $this->msd->id, 'office_designation' => 'division_chief']);
        $this->actingAs($editor)->put("/users/{$chief->id}", ['first_name' => 'Ana', 'last_name' => 'Cruz', 'username' => 'acruz', 'email' => $chief->email])
            ->assertSessionHasNoErrors();
        $this->assertSame(OfficeDesignation::DivisionChief, $chief->fresh()->office_designation);
    }

    public function test_moving_office_or_deactivating_ends_the_admin_access(): void
    {
        $system = $this->system($this->msd, ['Admin']);
        $chief = User::factory()->create(['organizational_unit_id' => $this->msd->id]);
        $this->actingAs($this->admin)->put("/users/{$chief->id}", $this->profile($chief, 'division_chief'));
        $this->assertSame('Admin', $this->roleIn($chief, $system));

        $this->actingAs($this->admin)->patch("/users/{$chief->id}/status", ['status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertNull($this->roleIn($chief, $system));
        $this->actingAs($this->admin)->patch("/users/{$chief->id}/status", ['status' => 'active']);
        $this->assertSame('Admin', $this->roleIn($chief, $system));

        // A reassignment moves the office without the form: the designation lapses.
        $chief->fresh()->update(['organizational_unit_id' => $this->fieldOffice->id]);
        $this->assertNull($chief->fresh()->office_designation);
        $this->artisan('imis:sync-office-admins')->assertSuccessful();
        $this->assertNull($this->roleIn($chief, $system));
    }

    public function test_systems_and_roles_added_later_follow(): void
    {
        $chief = User::factory()->create(['organizational_unit_id' => $this->msd->id]);
        $this->actingAs($this->admin)->put("/users/{$chief->id}", $this->profile($chief, 'division_chief'));
        $system = $this->system($this->msd, ['User']);

        $this->actingAs($this->admin)->post("/admin/applications/{$system->id}/roles", ['name' => 'Admin', 'sort_order' => 0])->assertSessionHasNoErrors();

        $this->assertSame('Admin', $this->roleIn($chief, $system));
    }

    public function test_the_users_page_shows_and_offers_it(): void
    {
        User::factory()->create(['organizational_unit_id' => $this->msd->id, 'office_designation' => 'division_chief']);

        $this->actingAs($this->admin)->get('/users')->assertInertia(fn (Assert $page) => $page
            ->where('canDesignate', true)
            ->has('designationOptions', 2)
            ->where('users', fn ($users) => collect($users)->contains('designation_label', 'Division Chief')));
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function system(?OrganizationalUnit $office, array $roles): Application
    {
        $application = Application::factory()->create(['organizational_unit_id' => $office?->id]);

        foreach ($roles as $name) {
            ApplicationRole::factory()->for($application)->create(['name' => $name]);
        }

        return $application;
    }

    private function grant(User $user, Application $application, string $role): void
    {
        $access = $application->accessGrants()->make([
            'user_id' => $user->id,
            'application_role_id' => $application->roles()->where('name', $role)->sole()->id,
        ]);
        $access->save();
    }

    private function roleIn(User $user, Application $application): ?string
    {
        return ApplicationAccess::query()->where('user_id', $user->id)->where('application_id', $application->id)->first()?->role->name;
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user, ?string $designation): array
    {
        return [
            'first_name' => $user->first_name ?? 'Juan',
            'last_name' => $user->last_name ?? 'Cruz',
            'username' => $user->username,
            'email' => $user->email,
            'organizational_unit_id' => $user->organizational_unit_id,
            'office_designation' => $designation,
        ];
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
