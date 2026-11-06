<?php

namespace Tests\Feature\HR;

use App\Enums\LedgerEntryKind;
use App\Models\Employee;
use App\Models\LeaveCardMonth;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Leave\LeaveCardSync;
use App\Support\HR\Leave\LeaveCardWorkbook;
use App\Support\HR\Leave\LeaveCredits;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;
use ZipArchive;

/**
 * Syncing IMIS with HR's leave card workbook (the master file): history
 * refreshed, and any balance difference at the card's last month posted as
 * one visible ledger entry.
 */
class LeaveCardSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);
        Storage::fake('local');
    }

    public function test_a_difference_at_the_cards_last_month_is_posted_as_one_adjustment_per_type(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $this->ledger($ana, 'VL', LedgerEntryKind::Opening, 10, '2026-01-31');
        $this->ledger($ana, 'SL', LedgerEntryKind::Opening, 20, '2026-01-31');
        $hr = $this->hr();

        $plan = $this->plan(['REYES' => $this->card('ANA B. REYES', 2026, [1 => [10.0, 20.0], 2 => [11.0, 20.5]])]);
        $row = $plan->first();

        $this->assertSame('adjust', $row['sync']['status']);
        $this->assertSame('2026-02-28', $row['sync']['date']);
        $this->assertSame(['card' => 11.0, 'system' => 10.0, 'diff' => 1.0, 'kind' => 'adjustment'], $row['sync']['vl']);

        $counts = $this->sync()->apply($plan, [$ana->id], $hr, 'cards.xlsx');

        $this->assertSame(['people' => 1, 'months' => 3, 'adjustments' => 2, 'openings' => 0], $counts);
        $entry = LeaveLedgerEntry::query()->where('employee_id', $ana->id)->where('kind', LedgerEntryKind::Adjustment)->orderBy('leave_type_id')->first();
        $this->assertSame('2026-02-28', $entry->entry_date->toDateString());
        $this->assertEquals(1.0, $entry->amount);
        $this->assertSame($hr->id, $entry->recorded_by);
        $this->assertStringContainsString('Synced with the leave card file (cards.xlsx)', $entry->particulars);
        $this->assertSame(11.0, app(LeaveCredits::class)->balance($ana, LeaveType::query()->where('code', 'VL')->first(), now()->setDate(2026, 2, 28)));
    }

    public function test_syncing_again_posts_nothing_once_the_system_agrees_with_the_card(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $this->ledger($ana, 'VL', LedgerEntryKind::Opening, 10, '2026-01-31');
        $this->ledger($ana, 'SL', LedgerEntryKind::Opening, 20, '2026-01-31');
        $sheets = ['REYES' => $this->card('ANA B. REYES', 2026, [1 => [10.0, 20.0], 2 => [11.0, 20.5]])];

        $this->sync()->apply($this->plan($sheets), [$ana->id], $this->hr(), 'cards.xlsx');
        $again = $this->plan($sheets);
        $entries = LeaveLedgerEntry::query()->count();
        $counts = $this->sync()->apply($again, [$ana->id], $this->hr(), 'cards.xlsx');

        $this->assertSame('in_sync', $again->first()['sync']['status']);
        $this->assertSame(0, $counts['adjustments'] + $counts['openings']);
        $this->assertSame($entries, LeaveLedgerEntry::query()->count());
        $this->assertSame(3, LeaveCardMonth::query()->where('employee_id', $ana->id)->count(), 'History is refreshed in place, not duplicated.');
    }

    public function test_someone_with_no_entries_of_a_type_gets_an_opening_balance(): void
    {
        $ana = $this->employee('Ana', 'Reyes');

        $counts = $this->sync()->apply($this->plan(['REYES' => $this->card('ANA B. REYES', 2026, [1 => [10.0, 20.0]])]), [$ana->id], $this->hr(), 'cards.xlsx');

        $this->assertSame(2, $counts['openings']);
        $this->assertSame(2, LeaveLedgerEntry::query()->where('kind', LedgerEntryKind::Opening)->count());
    }

    public function test_only_the_people_chosen_are_synced(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $ben = $this->employee('Ben', 'Cruz');

        $counts = $this->sync()->apply($this->plan([
            'REYES' => $this->card('ANA B. REYES', 2026, [1 => [10.0, 20.0]]),
            'CRUZ' => $this->card('BEN D. CRUZ', 2026, [1 => [30.0, 40.0]]),
        ]), [$ben->id], $this->hr(), 'cards.xlsx');

        $this->assertSame(1, $counts['people']);
        $this->assertSame(0, LeaveLedgerEntry::query()->where('employee_id', $ana->id)->count());
        $this->assertSame(0, LeaveCardMonth::query()->where('employee_id', $ana->id)->count());
        $this->assertSame(2, LeaveLedgerEntry::query()->where('employee_id', $ben->id)->count());
    }

    public function test_a_big_difference_is_flagged_and_entries_that_start_after_the_card_are_left_alone(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $this->ledger($ana, 'VL', LedgerEntryKind::Opening, 10, '2026-01-31');
        $this->ledger($ana, 'SL', LedgerEntryKind::Opening, 20, '2026-01-31');
        $ben = $this->employee('Ben', 'Cruz');
        $this->ledger($ben, 'VL', LedgerEntryKind::Opening, 7, '2026-06-30'); // after the card's last month
        $this->ledger($ben, 'SL', LedgerEntryKind::Opening, 7, '2026-06-30');

        $plan = $this->plan([
            'REYES' => $this->card('ANA B. REYES', 2026, [1 => [50.0, 20.0]]),
            'CRUZ' => $this->card('BEN D. CRUZ', 2026, [1 => [30.0, 40.0]]),
        ])->keyBy('sheet');

        $this->assertSame('large', $plan['REYES']['sync']['status']);
        $this->assertSame('check', $plan['CRUZ']['sync']['status']);

        $this->sync()->apply($plan->values(), [$ben->id], $this->hr(), 'cards.xlsx');

        $this->assertSame(2, LeaveLedgerEntry::query()->where('employee_id', $ben->id)->count(), 'No balance entry for a card the system cannot be compared with.');
        $this->assertSame(2, LeaveCardMonth::query()->where('employee_id', $ben->id)->count(), 'Its history (brought forward and January) is still saved.');
    }

    public function test_sheets_without_an_employee_are_reported_not_synced(): void
    {
        $plan = $this->plan(['NOBODY' => $this->card('ZED Q. NOBODY', 2026, [1 => [10.0, 20.0]])]);

        $this->assertFalse($plan->first()['ready']);
        $this->assertNull($plan->first()['sync']);
        $this->assertSame(0, $this->sync()->apply($plan, [1], $this->hr(), 'cards.xlsx')['people']);
    }

    public function test_only_leave_managers_can_open_the_sync_page(): void
    {
        $this->actingAs($this->userWith(['leave.view']))->get('/admin/hr/leave/sync')->assertForbidden();
        $this->actingAs($this->userWith([]))->get('/admin/hr/leave/sync')->assertForbidden();
        $this->actingAs($this->hr())->get('/admin/hr/leave/sync')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/Leave/Sync')->where('upload', null));
    }

    public function test_upload_review_and_apply_through_the_page(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $this->ledger($ana, 'VL', LedgerEntryKind::Opening, 10, '2026-01-31');
        $this->ledger($ana, 'SL', LedgerEntryKind::Opening, 20, '2026-01-31');
        $hr = $this->hr();
        $file = $this->workbookFile(['REYES' => $this->card('ANA B. REYES', 2026, [1 => [10.0, 20.0], 2 => [11.0, 20.5]])]);

        $this->actingAs($hr)->post('/admin/hr/leave/sync', ['file' => $file])->assertRedirect('/admin/hr/leave/sync');
        $this->get('/admin/hr/leave/sync')->assertInertia(fn (Assert $page) => $page
            ->where('upload.name', 'cards.xlsx')
            ->where('summary.adjust', 1)
            ->where('rows.0.employee_id', $ana->id)
            ->where('rows.0.sync.vl.diff', 1));
        $this->assertCount(1, Storage::disk('local')->files('leave-sync'));

        $this->post('/admin/hr/leave/sync/apply', ['employee_ids' => [$ana->id]])->assertRedirect('/admin/hr/leave');

        $this->assertSame(2, LeaveLedgerEntry::query()->where('kind', LedgerEntryKind::Adjustment)->count());
        $this->assertSame([], Storage::disk('local')->files('leave-sync'), 'The upload is deleted once applied.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'leave_credit.synced_from_workbook']);
    }

    public function test_applying_needs_someone_chosen_and_a_workbook_still_waiting(): void
    {
        $hr = $this->hr();

        $this->actingAs($hr)->post('/admin/hr/leave/sync/apply', ['employee_ids' => []])->assertSessionHasErrors('employee_ids');
        $this->post('/admin/hr/leave/sync/apply', ['employee_ids' => [1]])->assertRedirect('/admin/hr/leave/sync')->assertSessionHas('error');
    }

    private function sync(): LeaveCardSync
    {
        return app(LeaveCardSync::class);
    }

    /**
     * @param  array<string, array<int, array<string, string>>>  $sheets
     */
    private function plan(array $sheets)
    {
        $workbook = Mockery::mock(LeaveCardWorkbook::class)->makePartial();
        $workbook->shouldReceive('read')->andReturn($sheets);

        return (new LeaveCardSync($workbook, app(LeaveCredits::class)))->plan('ignored.xlsx');
    }

    private function employee(string $first, string $last): Employee
    {
        return Employee::factory()->for(User::factory()->state(['first_name' => $first, 'last_name' => $last]))->create();
    }

    private function ledger(Employee $employee, string $code, LedgerEntryKind $kind, float $amount, string $date): void
    {
        (new LeaveLedgerEntry)->forceFill(['employee_id' => $employee->id, 'leave_type_id' => LeaveType::query()->where('code', $code)->value('id'),
            'entry_date' => $date, 'kind' => $kind, 'amount' => $amount, 'particulars' => 'Test entry'])->save();
    }

    private function hr(): User
    {
        return $this->userWith(['leave.manage']);
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

    /**
     * A year's card block: name, year, PERIOD heading, brought forward, then two rows a month
     * (credits earned, then the month-end balance); [month => [VL balance, SL balance]].
     *
     * @param  array<int, array{0: float, 1: float}>  $months
     * @return array<int, array<string, string>>
     */
    private function card(string $name, int $year, array $months): array
    {
        $names = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar'];
        $rows = [
            1 => ['A' => $name],
            2 => ['B' => (string) $year],
            3 => ['A' => 'PERIOD', 'C' => 'VACATION LEAVE'],
            4 => ['C' => 'EARNED'],
            5 => ['A' => 'Balance Brought Forward from Dec. 31, '.($year - 1), 'E' => '0', 'I' => '0'],
        ];
        $line = 6;
        foreach ($months as $month => [$vl, $sl]) {
            $rows[$line++] = ['A' => "{$names[$month]}. 1-28, {$year}", 'C' => '1.25', 'D' => '0', 'G' => '1.25', 'H' => '0'];
            $rows[$line++] = ['A' => "{$names[$month]}. 28, {$year}", 'E' => (string) $vl, 'I' => (string) $sl];
        }

        return $rows;
    }

    /**
     * A minimal real .xlsx (inline strings) of the sheets, as an upload.
     *
     * @param  array<string, array<int, array<string, string>>>  $sheets
     */
    private function workbookFile(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lc').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

        $sheetXml = '';
        $relationships = '';
        $index = 1;
        foreach ($sheets as $name => $rows) {
            $sheetXml .= '<sheet name="'.htmlspecialchars($name).'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $relationships .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';

            $data = '';
            foreach ($rows as $number => $cells) {
                $data .= '<row r="'.$number.'">';
                foreach ($cells as $column => $value) {
                    $data .= '<c r="'.$column.$number.'" t="inlineStr"><is><t>'.htmlspecialchars($value).'</t></is></c>';
                }
                $data .= '</row>';
            }
            $zip->addFromString("xl/worksheets/sheet{$index}.xml", '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$data.'</sheetData></worksheet>');
            $index++;
        }

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheetXml.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>');
        $zip->close();

        return new UploadedFile($path, 'cards.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
