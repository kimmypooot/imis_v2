<?php

namespace Tests\Feature\HR;

use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\PlantillaItem;
use App\Models\Position;
use App\Models\Role;
use App\Models\SalarySchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * HR-4 positions: each title's salary grade and level, which the
 * plantilla items of that title follow.
 */
class PositionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_needs_plantilla_permissions_and_viewing_alone_cannot_change_anything(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/hr/positions')->assertForbidden();

        $viewer = $this->userWith(['plantilla.view']);
        $this->actingAs($viewer)->get('/admin/hr/positions')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/Positions/Index')->where('canManage', false)->where('form', null));
        $this->actingAs($viewer)->post('/admin/hr/positions', ['title' => 'Attorney V'])->assertForbidden();
        $this->actingAs($viewer)->post('/admin/hr/positions/from-staff')->assertForbidden();
    }

    public function test_titles_on_staff_accounts_become_positions_with_the_grade_blank(): void
    {
        $hr = $this->hr();
        $hr->forceFill(['position' => 'Senior Human Resource Specialist'])->save();
        User::factory()->create(['position' => ' senior human resource specialist ']);
        User::factory()->create(['position' => 'Attorney V']);
        Position::query()->create(['title' => 'Attorney V', 'salary_grade' => 22]);

        $this->actingAs($hr)->post('/admin/hr/positions/from-staff')->assertSessionHas('status', 'Added 1 position. Enter their salary grades.');

        $this->assertSame(['Attorney V', 'Senior Human Resource Specialist'], Position::query()->orderBy('title')->pluck('title')->all());
        $this->assertNull(Position::query()->where('title', 'Senior Human Resource Specialist')->value('salary_grade'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'position.added_from_staff']);

        $this->actingAs($hr)->get('/admin/hr/positions')->assertInertia(fn (Assert $page) => $page
            ->where('positions.1.title', 'Senior Human Resource Specialist')
            ->where('positions.1.staff_count', 2));
    }

    public function test_setting_a_grade_adjusts_every_item_of_that_title(): void
    {
        $hr = $this->hr();
        $first = $this->item('CSCB-SHRS-1', 'Senior Human Resource Specialist', 15);
        $second = $this->item('CSCB-SHRS-2', 'senior human resource specialist', 15);
        $other = $this->item('CSCB-ATY-1', 'Attorney V', 22);

        $this->actingAs($hr)->post('/admin/hr/positions', ['title' => 'Senior Human Resource Specialist', 'salary_grade' => 18, 'level' => 'second'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Senior Human Resource Specialist was added; 2 plantilla items adjusted.');

        $position = Position::query()->sole();
        foreach ([$first, $second] as $item) {
            $item->refresh();
            $this->assertSame($position->id, $item->position_id);
            $this->assertSame(18, $item->salary_grade);
            $this->assertSame('second', $item->level->value);
            $this->assertSame('Senior Human Resource Specialist', $item->position_title);
        }
        $this->assertNull($other->refresh()->position_id);
        $this->assertSame(22, $other->salary_grade);

        // Changing the grade (and the title) follows through to the items.
        $this->actingAs($hr)->put("/admin/hr/positions/{$position->id}", ['title' => 'Senior HR Specialist', 'salary_grade' => 19])
            ->assertSessionHas('status', 'Senior HR Specialist was saved; 2 plantilla items adjusted.');
        $this->assertSame(19, $first->refresh()->salary_grade);
        $this->assertSame('Senior HR Specialist', $first->position_title);
        $this->assertDatabaseHas('audit_logs', ['event' => 'position.updated', 'target_id' => $position->id]);
    }

    public function test_a_new_item_takes_its_positions_grade(): void
    {
        $position = Position::query()->create(['title' => 'Attorney V', 'salary_grade' => 22, 'level' => 'second']);

        $item = $this->item('CSCB-ATY-1', ' attorney v', 1);

        $this->assertSame($position->id, $item->position_id);
        $this->assertSame(22, $item->salary_grade);
        $this->assertSame('Attorney V', $item->position_title);
    }

    public function test_a_position_in_use_cannot_be_deleted(): void
    {
        $hr = $this->hr();
        $used = Position::query()->create(['title' => 'Attorney V', 'salary_grade' => 22]);
        $this->item('CSCB-ATY-1', 'Attorney V', 22);
        $unused = Position::query()->create(['title' => 'Director II']);

        $this->actingAs($hr)->delete("/admin/hr/positions/{$used->id}")->assertSessionHas('error');
        $this->assertModelExists($used);

        $this->actingAs($hr)->delete("/admin/hr/positions/{$unused->id}")->assertSessionHas('status');
        $this->assertModelMissing($unused);
    }

    public function test_the_page_shows_salary_items_with_their_place_of_assignment_and_where_staff_are(): void
    {
        $hr = $this->hr();
        $hrd = OrganizationalUnit::factory()->create(['name' => 'Human Resource Division', 'short_name' => 'HRD']);
        $schedule = SalarySchedule::query()->create(['name' => 'Third tranche, 2026', 'reference' => 'NBC No. 601', 'effective_date' => '2026-01-01']);
        $schedule->rates()->create(['salary_grade' => 22, 'step' => 1, 'monthly_salary' => 71511]);
        Position::query()->create(['title' => 'Attorney V', 'salary_grade' => 22]);
        Position::query()->create(['title' => 'Director II']);
        PlantillaItem::query()->create(['item_number' => 'CSCB-ATY-1', 'position_title' => 'Attorney V', 'organizational_unit_id' => $hrd->id]);
        User::factory()->count(2)->create(['position' => 'Director II', 'organizational_unit_id' => $hrd->id]);

        $this->actingAs($hr)->get('/admin/hr/positions')->assertInertia(fn (Assert $page) => $page
            ->where('schedule.name', 'Third tranche, 2026')
            ->where('positions.0.title', 'Attorney V')
            ->where('positions.0.monthly_salary', 71511)
            ->where('positions.0.items.0.item_number', 'CSCB-ATY-1')
            ->where('positions.0.items.0.office', 'HRD')
            ->where('positions.0.items.0.holder', null)
            // No grade, no item yet: no salary, but where its staff are.
            ->where('positions.1.monthly_salary', null)
            ->where('positions.1.items', [])
            ->where('positions.1.staff_offices', [['office' => 'HRD', 'count' => 2]]));
    }

    public function test_an_item_added_from_a_position_takes_its_grade_and_may_wait_for_its_number(): void
    {
        $hr = $this->hr();
        $position = Position::query()->create(['title' => 'Attorney V', 'salary_grade' => 22, 'level' => 'second']);
        $hrd = OrganizationalUnit::factory()->create();

        $this->actingAs($hr)->from('/admin/hr/positions')->post('/admin/hr/plantilla', [
            'position_title' => 'Attorney V',
            'item_number' => null,
            'organizational_unit_id' => $hrd->id,
            'stay' => true,
        ])->assertRedirect('/admin/hr/positions')->assertSessionHasNoErrors();

        $item = PlantillaItem::query()->sole();
        $this->assertNull($item->item_number);
        $this->assertSame($position->id, $item->position_id);
        $this->assertSame(22, $item->salary_grade);
        $this->assertSame($hrd->id, $item->organizational_unit_id);

        // A second item without a number is fine too; numbers that are given stay unique.
        $this->actingAs($hr)->post('/admin/hr/plantilla', ['position_title' => 'Attorney V', 'stay' => true])->assertSessionHasNoErrors();
        $this->assertSame(2, PlantillaItem::query()->count());
    }

    private function item(string $number, string $title, int $grade): PlantillaItem
    {
        return PlantillaItem::query()->create(['item_number' => $number, 'position_title' => $title, 'salary_grade' => $grade]);
    }

    private function hr(): User
    {
        return $this->userWith(['plantilla.manage']);
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
