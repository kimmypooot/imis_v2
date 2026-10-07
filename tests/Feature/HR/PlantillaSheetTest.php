<?php

namespace Tests\Feature\HR;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Plantilla\PlantillaSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Completing the plantilla from a spreadsheet: download the template,
 * upload it filled in, review every row, then apply.
 */
class PlantillaSheetTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $hrd;

    private OrganizationalUnit $samar;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 09:00:00');
        $this->hrd = OrganizationalUnit::factory()->create(['name' => 'Human Resource Division', 'short_name' => 'HRD']);
        $this->samar = OrganizationalUnit::factory()->create(['name' => 'CSC Field Office - Samar', 'short_name' => 'SFO']);
        $this->hr = $this->userWith(['plantilla.manage']);
    }

    public function test_the_template_lists_active_items_with_what_is_on_record_and_needs_manage(): void
    {
        [$item] = $this->linked('Ana', 'Attorney V');
        PlantillaItem::query()->create(['item_number' => 'OLD-1', 'position_title' => 'Clerk'])->forceFill(['abolished_at' => now()])->save();

        $csv = $this->actingAs($this->hr)->get('/admin/hr/plantilla-sheet/template')->assertOk()->streamedContent();

        $this->assertStringContainsString('Item ID (do not change)', $csv);
        $this->assertStringContainsString("{$item->id},Ana,\"Attorney V\",,,\"Human Resource Division\"", $csv);
        $this->assertStringNotContainsString('OLD-1', $csv);

        $viewer = $this->userWith(['plantilla.view']);
        $this->actingAs($viewer)->get('/admin/hr/plantilla-sheet/template')->assertForbidden();
        $this->actingAs($viewer)->get('/admin/hr/plantilla-sheet')->assertForbidden();
    }

    public function test_a_filled_sheet_is_checked_then_applied_completing_the_links(): void
    {
        [$item, $link, $ana] = $this->linked('Ana', 'Attorney V');

        $this->upload([[$item->id, 'Ana', 'Attorney V', 'ATY5-1', '22', 'SFO', '2', 'Permanent', 'original', '3/1/2019']])
            ->assertRedirect('/admin/hr/plantilla-sheet');

        // Nothing saved yet; the review shows each change.
        $this->assertNull($item->fresh()->item_number);
        $this->actingAs($this->hr)->get('/admin/hr/plantilla-sheet')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/HR/Plantilla/Sheet')
            ->where('summary.ready', 1)
            ->where('summary.errors', 0)
            ->where('rows.0.changes', fn ($changes) => collect($changes)->pluck('to')->all() === ['ATY5-1', '22', 'CSC Field Office - Samar', '2', 'Permanent', 'Original', '2019-03-01']));

        $this->actingAs($this->hr)->post('/admin/hr/plantilla-sheet/apply')
            ->assertRedirect('/admin/hr/plantilla')->assertSessionHas('status', 'Updated 1 item from the spreadsheet.');

        $item->refresh();
        $this->assertSame('ATY5-1', $item->item_number);
        $this->assertSame(22, $item->salary_grade);
        $this->assertSame($this->samar->id, $item->organizational_unit_id);
        $this->assertTrue($link->fresh()->isComplete());
        $this->assertSame('2019-03-01', $link->fresh()->date_from->toDateString());
        $this->assertSame(EmploymentStatus::Permanent, $ana->fresh()->employment_status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'plantilla_item.sheet_applied']);

        // The upload is used up.
        $this->actingAs($this->hr)->get('/admin/hr/plantilla-sheet')->assertInertia(fn (Assert $page) => $page->where('upload', null));
    }

    public function test_bad_rows_are_reported_and_skipped_while_good_ones_apply(): void
    {
        [$good] = $this->linked('Ana', 'Attorney V');
        [$badStatus] = $this->linked('Ben', 'Director II');
        [$own] = $this->linked('Me', 'Chief Human Resource Specialist', $this->hr);
        $vacant = PlantillaItem::query()->create(['position_title' => 'Clerk']);
        [$graded] = $this->linked('Cora', 'Attorney VI');
        Position::query()->where('title', 'Attorney VI')->update(['salary_grade' => 24]);
        [$several] = $this->linked('Dan', 'Director II');
        PlantillaItem::query()->create(['item_number' => 'TAKEN-1', 'position_title' => 'Clerk']);

        $this->upload([
            [$good->id, '', '', 'ATY5-1', '', '', '', '', '', ''],
            [$badStatus->id, '', '', '', '', '', '', 'Casual', '', ''],
            [$own->id, '', '', '', '', '', '1', '', '', ''],
            [$vacant->id, '', '', '', '', '', '1', '', '', ''],
            [$graded->id, '', '', '', '20', '', '', '', '', ''],
            [99999, '', '', '', '', '', '', '', '', ''],
            // The same item twice: only the first line counts.
            [$badStatus->id, '', '', '', '', '', '1', '', '', ''],
            [$several->id, '', '', 'TAKEN-1', '', 'Nowhere', '', '', '', '2030-01-01'],
        ]);

        $this->actingAs($this->hr)->get('/admin/hr/plantilla-sheet')->assertInertia(fn (Assert $page) => $page
            ->where('summary.ready', 1)
            ->where('summary.errors', 7)
            ->where('rows.1.errors.0', fn ($error) => str_starts_with($error, 'Status must be one of'))
            ->where('rows.2.errors.0', 'This is your own appointment; another HR officer has to complete it.')
            ->where('rows.3.errors.0', fn ($error) => str_starts_with($error, 'The item is vacant'))
            ->where('rows.4.errors.0', fn ($error) => str_contains($error, 'Positions list'))
            ->where('rows.5.errors.0', fn ($error) => str_starts_with($error, 'No active item'))
            ->where('rows.6.errors', fn ($errors) => count($errors) === 1 && str_contains($errors[0], 'line 3'))
            ->where('rows.7.errors', fn ($errors) => count($errors) === 3
                && str_contains($errors[0], 'already another item')
                && str_contains($errors[1], 'No office called "Nowhere"')
                && str_contains($errors[2], 'between 1950 and today')));

        $this->actingAs($this->hr)->post('/admin/hr/plantilla-sheet/apply')->assertSessionHas('status', 'Updated 1 item from the spreadsheet. 7 rows had errors and were skipped.');
        $this->assertSame('ATY5-1', $good->fresh()->item_number);
        // A blank cell leaves the value as it was.
        $this->assertSame($this->hrd->id, $good->fresh()->organizational_unit_id);
        $this->assertNull($own->incumbency->fresh()->step);
    }

    public function test_a_file_that_is_not_the_template_is_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('other.csv', "Name,Position\nAna,Attorney V\n");

        $this->actingAs($this->hr)->post('/admin/hr/plantilla-sheet', ['file' => $file])->assertSessionHasErrors('file');
        $this->actingAs($this->hr)->get('/admin/hr/plantilla-sheet')->assertInertia(fn (Assert $page) => $page->where('upload', null));
    }

    /**
     * An incomplete link like `imis:link-plantilla` makes, in HRD.
     *
     * @return array{0: PlantillaItem, 1: PlantillaAppointment, 2: Employee}
     */
    private function linked(string $name, string $title, ?User $user = null): array
    {
        $user ??= User::factory()->create();
        $user->forceFill(['name' => $name, 'position' => $title, 'organizational_unit_id' => $this->hrd->id])->save();
        Position::query()->firstOrCreate(['title' => $title]);
        $employee = Employee::factory()->for($user)->create(['employment_status' => null]);
        $item = PlantillaItem::query()->create(['position_title' => $title, 'organizational_unit_id' => $this->hrd->id]);
        $link = new PlantillaAppointment;
        $link->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id])->save();

        return [$item, $link, $employee];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function upload(array $rows): TestResponse
    {
        $lines = [implode(',', array_map(fn (array $column) => '"'.$column[1].'"', PlantillaSheet::COLUMNS))];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($cell) => '"'.$cell.'"', $row));
        }

        $file = UploadedFile::fake()->createWithContent('plantilla.csv', "\xEF\xBB\xBF".implode("\n", $lines)."\n");

        return $this->actingAs($this->hr)->post('/admin/hr/plantilla-sheet', ['file' => $file]);
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
