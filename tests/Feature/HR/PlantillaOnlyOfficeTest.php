<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Plantilla\Plantilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Leyte field office exists only so plantilla items can name it as their
 * place of assignment: the two offices under it are where people work.
 */
class PlantillaOnlyOfficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plantilla_only_office_is_left_out_of_working_office_lists(): void
    {
        $leyte = OrganizationalUnit::factory()->create(['plantilla_only' => true]);
        $first = OrganizationalUnit::factory()->childOf($leyte)->create();
        $samar = OrganizationalUnit::factory()->create();

        $ids = OrganizationalUnit::query()->workingOffices()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$first->id, $samar->id], $ids);
    }

    public function test_the_holder_of_the_parent_item_supervises_the_offices_under_it(): void
    {
        $leyte = OrganizationalUnit::factory()->create(['plantilla_only' => true]);
        $first = OrganizationalUnit::factory()->childOf($leyte)->create();
        $second = OrganizationalUnit::factory()->childOf($leyte)->create();
        $other = OrganizationalUnit::factory()->create();

        $director = User::factory()->create(['organizational_unit_id' => $first->id]);
        $record = Employee::factory()->for($director)->create();
        $item = PlantillaItem::query()->create(['position_title' => 'Director II', 'salary_grade' => 24, 'organizational_unit_id' => $leyte->id]);
        PlantillaAppointment::query()->forceCreate(['plantilla_item_id' => $item->id, 'employee_id' => $record->id, 'date_from' => '2026-01-01']);

        $this->grantPermission($director, 'employees.view_unit');

        $offices = Employee::officesSupervisedBy($director);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $offices);
        $this->assertNotContains($other->id, $offices);
    }

    public function test_an_appointment_to_a_plantilla_only_office_leaves_the_account_where_they_work(): void
    {
        $leyte = OrganizationalUnit::factory()->create(['plantilla_only' => true]);
        $first = OrganizationalUnit::factory()->childOf($leyte)->create();
        $user = User::factory()->create(['organizational_unit_id' => $first->id]);
        $item = PlantillaItem::query()->create(['position_title' => 'Director II', 'salary_grade' => 24, 'organizational_unit_id' => $leyte->id]);

        $method = new \ReflectionMethod(Plantilla::class, 'followItemOffice');
        $appointment = PlantillaAppointment::query()->forceCreate(['plantilla_item_id' => $item->id, 'employee_id' => Employee::factory()->for($user)->create()->id, 'date_from' => '2026-01-01']);
        $method->invoke(app(Plantilla::class), $appointment);

        $this->assertSame($first->id, $user->fresh()->organizational_unit_id);
    }

    private function grantPermission(User $user, string $permission): void
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission, 'group' => 'test'])->id);
        $user->roles()->attach($role);
    }
}
