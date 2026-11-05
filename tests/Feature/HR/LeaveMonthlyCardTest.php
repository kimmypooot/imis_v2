<?php

namespace Tests\Feature\HR;

use App\Enums\LedgerEntryKind;
use App\Models\Employee;
use App\Models\LeaveCardMonth;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\HR\Leave\LeaveCardWorkbook;
use App\Support\HR\Leave\LeaveCredits;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The monthly leave card (the paper/Excel layout): history imported from
 * HR's workbook, then months built from the ledger once it takes over.
 */
class LeaveMonthlyCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);
    }

    public function test_history_reads_every_month_with_the_years_brought_forward_row(): void
    {
        $ana = $this->employee();
        $sheets = ['REYES' => $this->workbook([2025 => [10.0, 20.0, [1 => [1.25, 0.5], 2 => [1.25, 0]]], 2026 => [8.0, 18.0, [1 => [1.25, 0]]]])];

        $checked = (new LeaveCardWorkbook)->history($sheets);
        $saved = (new LeaveCardWorkbook)->applyHistory($checked);

        $this->assertSame(5, $saved); // two brought-forward rows and three months
        $january = LeaveCardMonth::query()->where(['employee_id' => $ana->id, 'year' => 2025, 'month' => 1])->first();
        $this->assertSame(1.25, $january->vl_earned);
        $this->assertSame(0.5, $january->vl_used);
        $this->assertSame(10.75, $january->vl_balance);
        $this->assertSame(8.0, LeaveCardMonth::query()->where(['year' => 2026, 'month' => 0])->value('vl_balance'));
        $this->assertSame(0, LeaveLedgerEntry::query()->count(), 'History never touches the ledger.');
    }

    public function test_rerunning_updates_in_place_and_months_without_credits_are_skipped(): void
    {
        $this->employee();
        $sheets = ['REYES' => $this->workbook([2026 => [10.0, 20.0, [1 => [1.25, 0]]]])];
        $sheets['REYES'][] = ['A' => 'Feb. 1-28, 2026', 'B' => '(00-00-00)']; // Not entered yet.

        (new LeaveCardWorkbook)->applyHistory((new LeaveCardWorkbook)->history($sheets));
        (new LeaveCardWorkbook)->applyHistory((new LeaveCardWorkbook)->history($sheets));

        $this->assertSame(2, LeaveCardMonth::query()->count()); // brought forward + January
    }

    public function test_only_a_break_within_a_year_is_a_warning_not_the_year_end_difference(): void
    {
        $this->employee();
        // 2025 ends at 11.0, but 2026 is brought forward at 4.0: a year-end adjustment.
        $clean = ['REYES' => $this->workbook([2025 => [10.0, 20.0, [1 => [1.0, 0]]], 2026 => [4.0, 9.0, [1 => [1.25, 0]]]])];
        $this->assertSame([], (new LeaveCardWorkbook)->history($clean)->first()['warnings']);

        // January says 99 where 10 + 1.25 - 0 = 11.25 is expected.
        $broken = ['REYES' => $this->workbook([2026 => [10.0, 20.0, [1 => [1.25, 0]]]])];
        $broken['REYES'][7]['E'] = '99';
        $warnings = (new LeaveCardWorkbook)->history($broken)->first()['warnings'];
        $this->assertStringContainsString('VL 2026-01', $warnings[0]);
    }

    public function test_the_monthly_card_shows_the_card_then_continues_from_the_ledger(): void
    {
        $ana = $this->employee();
        foreach ([[0, null, null, 10.0, 20.0], [1, 1.25, 0.25, 11.0, 21.25], [2, 1.25, 0, 12.25, 22.5]] as [$month, $earned, $used, $vl, $sl]) {
            LeaveCardMonth::factory()->for($ana)->create(['year' => 2026, 'month' => $month, 'vl_earned' => $earned, 'vl_used' => $used,
                'vl_balance' => $vl, 'sl_earned' => $earned, 'sl_used' => 0, 'sl_balance' => $sl, 'particulars' => $month === 1 ? '(00-02-00) UT' : null]);
        }
        $credits = app(LeaveCredits::class);
        $vl = LeaveType::query()->where('code', 'VL')->first();
        $sl = LeaveType::query()->where('code', 'SL')->first();
        // The balance as of February 28 was carried into the ledger; March accrues and VL is taken.
        $this->ledger($ana, $vl, LedgerEntryKind::Opening, 12.25, '2026-02-28');
        $this->ledger($ana, $sl, LedgerEntryKind::Opening, 22.5, '2026-02-28');
        $credits->accrueMonth(2026, 3, null, [$ana->id]);
        $this->ledger($ana, $vl, LedgerEntryKind::Deduction, -2.0, '2026-03-12', 'VL 2 March 11-12, 2026');

        $card = $credits->monthlyCard($ana, 2026);

        $this->assertSame(['vl' => 10.0, 'sl' => 20.0, 'source' => 'card'], $card['forward']);
        $this->assertSame(['card', 'card', 'ledger'], array_column($card['months'], 'source'));
        $this->assertSame('(00-02-00) UT', $card['months'][0]['particulars']);
        $march = $card['months'][2];
        $this->assertSame(['earned' => 1.25, 'used' => 2.0, 'adjusted' => 0.0, 'balance' => 11.5], $march['vl']);
        $this->assertSame(23.75, $march['sl']['balance']);
        $this->assertStringContainsString('VL 2 March 11-12, 2026', $march['applications']);
    }

    public function test_a_person_with_no_card_history_or_entries_has_an_empty_monthly_card(): void
    {
        $card = app(LeaveCredits::class)->monthlyCard($this->employee(), 2026);

        $this->assertSame([], $card['months']);
        $this->assertNull($card['forward']);
    }

    public function test_the_leave_page_passes_the_monthly_card_and_offers_history_years(): void
    {
        $ana = $this->employee();
        LeaveCardMonth::factory()->for($ana)->create(['year' => 2019, 'month' => 5]);

        $this->actingAs($ana->user)->get('/my/leave?year=2019')
            ->assertInertia(fn (Assert $page) => $page->component('Portal/MyLeave')
                ->where('monthly.months.0.label', 'May')
                ->where('monthly.months.0.source', 'card')
                ->has('years', fn (Assert $years) => $years->where('0', (int) today()->year)->where('1', 2019)->etc()));
    }

    private function employee(): Employee
    {
        return Employee::factory()->for(User::factory()->state(['first_name' => 'Ana', 'last_name' => 'Reyes']))->create();
    }

    private function ledger(Employee $employee, LeaveType $type, LedgerEntryKind $kind, float $amount, string $date, string $particulars = 'Test entry'): void
    {
        (new LeaveLedgerEntry)->forceFill(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'entry_date' => $date,
            'kind' => $kind, 'amount' => $amount, 'particulars' => $particulars])->save();
    }

    /**
     * A card sheet: per year [VL forward, SL forward, [month => [earned, used]]], balances
     * following earned less used for both VL and SL.
     *
     * @param  array<int, array{0: float, 1: float, 2: array<int, array{0: float, 1: float}>}>  $years
     * @return array<int, array<string, string>>
     */
    private function workbook(array $years): array
    {
        $names = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar'];
        $rows = [1 => ['A' => 'ANA B. REYES']];
        $line = 2;
        foreach ($years as $year => [$vl, $sl, $months]) {
            $rows[$line++] = ['B' => (string) $year];
            $rows[$line++] = ['A' => 'PERIOD'];
            $rows[$line++] = ['C' => 'EARNED'];
            $rows[$line++] = ['A' => 'Balance Brought Forward from Dec. 31, '.($year - 1), 'E' => (string) $vl, 'I' => (string) $sl];
            foreach ($months as $month => [$earned, $used]) {
                $vl += $earned - $used;
                $sl += $earned;
                $rows[$line++] = ['A' => "{$names[$month]}. 1-28, {$year}", 'B' => '(00-00-00) ok', 'C' => (string) $earned, 'D' => (string) $used, 'G' => (string) $earned, 'H' => '0'];
                $rows[$line++] = ['A' => "{$names[$month]}. 28, {$year}", 'E' => (string) $vl, 'I' => (string) $sl];
            }
            $line += 2;
        }

        return $rows;
    }
}
