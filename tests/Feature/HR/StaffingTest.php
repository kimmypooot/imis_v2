<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Enums\UserStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Linking staff to the plantilla from their accounts (`imis:link-plantilla`),
 * and the staffing picture: each item's place of assignment against where
 * its holder actually serves, per office.
 */
class StaffingTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $hrd;

    private OrganizationalUnit $samar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 09:00:00');
        $this->hrd = OrganizationalUnit::factory()->create(['name' => 'Human Resource Division', 'short_name' => 'HRD']);
        $this->samar = OrganizationalUnit::factory()->create(['name' => 'CSC Field Office - Samar', 'short_name' => 'SFO']);
    }

    public function test_linking_previews_first_then_links_only_who_can_be_linked_without_guessing(): void
    {
        $ana = $this->staff('Ana', 'Attorney V', $this->hrd, EmploymentStatus::Permanent);
        $ben = $this->staff('Ben', 'Director II', $this->samar, null);
        $inactive = $this->staff('Cora', 'Director II', $this->hrd);
        $inactive->user->forceFill(['status' => UserStatus::Inactive])->save();
        $noTitle = $this->staff('Dan', null, $this->hrd);

        $this->artisan('imis:link-plantilla')->expectsOutputToContain('Would link 2 staff')->assertSuccessful();
        $this->assertSame(0, PlantillaItem::query()->count());

        $this->artisan('imis:link-plantilla', ['--apply' => true])->expectsOutputToContain('Linked 2 staff')->assertSuccessful();

        $link = $ana->plantillaAppointments()->with('item')->sole();
        $this->assertSame('Attorney V', $link->item->position_title);
        $this->assertSame($this->hrd->id, $link->item->organizational_unit_id);
        $this->assertNull($link->item->item_number);
        // Only what's on record: their plantilla status; no step, nature or start date.
        $this->assertSame(EmploymentStatus::Permanent, $link->status);
        $this->assertNull($link->step);
        $this->assertNull($link->date_from);
        $this->assertFalse($link->isComplete());

        $this->assertNull($ben->plantillaAppointments()->sole()->status);
        $this->assertFalse($inactive->plantillaAppointments()->exists());
        $this->assertFalse($noTitle->plantillaAppointments()->exists());
        $this->assertDatabaseHas('audit_logs', ['event' => 'plantilla_appointment.linked_from_accounts']);

        // Re-running links nobody twice.
        $this->artisan('imis:link-plantilla', ['--apply' => true])->expectsOutputToContain('Linked 0 staff')->assertSuccessful();
        $this->assertSame(2, PlantillaItem::query()->count());
    }

    public function test_an_incomplete_link_is_completed_through_correct_and_blocks_moves_until_then(): void
    {
        $hr = $this->userWith(['employees.view', 'plantilla.manage']);
        $ana = $this->staff('Ana', 'Attorney V', $this->hrd, null);
        $this->artisan('imis:link-plantilla', ['--apply' => true]);
        $link = $ana->plantillaAppointments()->with('item')->sole();
        $item = $link->item;
        $item->update(['salary_grade' => 22]);

        // A promotion needs the current period's start date first.
        $other = PlantillaItem::query()->create(['item_number' => 'ATY6-1', 'position_title' => 'Attorney VI', 'salary_grade' => 24]);
        $this->actingAs($hr)->post("/admin/hr/plantilla/{$other->id}/appointments", [
            'employee_id' => $ana->id, 'step' => 1, 'status' => 'permanent', 'nature' => 'promotion', 'date_from' => '2026-06-01',
        ])->assertSessionHasErrors('employee_id');

        $this->actingAs($hr)->get("/admin/hr/plantilla/{$item->id}")->assertInertia(fn (Assert $page) => $page
            ->where('item.holder.missing', ['step', 'status', 'nature', 'start date'])
            ->where('history.0.date_from', null));

        $this->actingAs($hr)->put("/admin/hr/plantilla/{$item->id}/appointments/{$link->id}", [
            'step' => 2, 'status' => 'permanent', 'nature' => 'original', 'date_from' => '2019-03-01',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($link->fresh()->isComplete());
        // The holder's status follows the completed link.
        $this->assertSame(EmploymentStatus::Permanent, $ana->fresh()->employment_status);
    }

    public function test_staffing_compares_each_items_place_of_assignment_with_where_its_holder_serves(): void
    {
        $hr = $this->userWith(['plantilla.view']);

        // Ana holds an HRD item but was reassigned to Samar by an order.
        $ana = $this->staff('Ana', 'Attorney V', $this->samar);
        $hrdItem = $this->held('ATY5-1', 'Attorney V', $this->hrd, $ana);
        $this->reassign($ana, $this->hrd, $this->samar, '2026-03-01', 'RO No. 12');
        // Ben holds a Samar item and serves there.
        $ben = $this->staff('Ben', 'Director II', $this->samar);
        $this->held('DIR2-1', 'Director II', $this->samar, $ben);
        // A vacant HRD item, and a Samar staff member with no item.
        PlantillaItem::query()->create(['item_number' => 'AO4-1', 'position_title' => 'Administrative Officer IV', 'salary_grade' => 15, 'organizational_unit_id' => $this->hrd->id]);
        $this->staff('Cora', 'Administrative Aide VI', $this->samar);

        $this->actingAs($hr)->get('/admin/hr/staffing')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/Staffing/Index')
            ->where('totals.items', 3)
            ->where('totals.vacant', 1)
            ->where('totals.elsewhere', 1)
            ->where('totals.without_item', 1)
            // CSC Field Office - Samar sorts before Human Resource Division.
            ->where('offices.0.name', 'CSC Field Office - Samar')
            ->where('offices.0.items', 1)
            ->where('offices.0.reassigned_in', 1)
            ->where('offices.0.present', 2)
            ->where('offices.0.without_item', 1)
            ->where('offices.1.name', 'Human Resource Division')
            ->where('offices.1.items', 2)
            ->where('offices.1.filled', 1)
            ->where('offices.1.vacant', 1)
            ->where('offices.1.reassigned_out', 1)
            ->where('offices.1.present', 0)
            ->where('elsewhere.0.name', 'Ana')
            ->where('elsewhere.0.place_of_assignment', 'HRD')
            ->where('elsewhere.0.office', 'SFO')
            ->where('elsewhere.0.order_number', 'RO No. 12')
            ->where('elsewhere.0.since', '2026-03-01')
            ->where('vacancies.0.item_number', 'AO4-1'));

        // The plantilla flags the item too.
        $this->actingAs($hr)->get('/admin/hr/plantilla')->assertInertia(fn (Assert $page) => $page
            ->where('items', fn ($items) => collect($items)->firstWhere('id', $hrdItem->id)['serving']['elsewhere'] === true));

        $this->actingAs($this->userWith([]))->get('/admin/hr/staffing')->assertForbidden();
    }

    private function staff(string $name, ?string $position, OrganizationalUnit $office, ?EmploymentStatus $status = EmploymentStatus::Permanent): Employee
    {
        $user = User::factory()->create(['name' => $name, 'position' => $position, 'organizational_unit_id' => $office->id]);

        return Employee::factory()->for($user)->create(['employment_status' => $status]);
    }

    private function held(string $number, string $title, OrganizationalUnit $office, Employee $employee): PlantillaItem
    {
        $item = PlantillaItem::query()->create(['item_number' => $number, 'position_title' => $title, 'salary_grade' => 20, 'organizational_unit_id' => $office->id]);
        $period = new PlantillaAppointment(['step' => 1, 'status' => 'permanent', 'nature' => 'original', 'date_from' => '2020-01-02']);
        $period->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id])->save();

        return $item;
    }

    private function reassign(Employee $employee, OrganizationalUnit $from, OrganizationalUnit $to, string $date, string $order): void
    {
        $move = new EmployeeAssignment(['kind' => 'reassignment', 'from_unit_id' => $from->id, 'to_unit_id' => $to->id, 'effective_date' => $date, 'order_number' => $order]);
        $move->forceFill(['employee_id' => $employee->id, 'applied_at' => now()])->save();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
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
