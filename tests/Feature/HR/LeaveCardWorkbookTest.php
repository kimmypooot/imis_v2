<?php

namespace Tests\Feature\HR;

use App\Enums\LedgerEntryKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\HR\Leave\LeaveCardWorkbook;
use App\Support\HR\Leave\LeaveCredits;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `imis:import-leave-cards`: opening VL/SL balances from HR's leave-card
 * workbook, checked against fictional sheets in the card's own layout.
 */
class LeaveCardWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private LeaveCardWorkbook $workbook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeaveTypeSeeder::class);
        $this->workbook = new LeaveCardWorkbook;
    }

    public function test_it_posts_the_last_month_end_balance_as_vl_and_sl_opening_entries(): void
    {
        $ana = $this->employee('Ana', 'Reyes');

        $checked = $this->workbook->check(['REYES' => $this->card('ANA B. REYES', 2026, [1 => [10.5, 20.25], 2 => [11.75, 21.5]])]);
        $posted = $this->workbook->apply($checked, 'cards.xlsx');

        $this->assertSame(2, $posted);
        $this->assertSame(11.75, $this->openingOf($ana, 'VL'));
        $this->assertSame(21.5, $this->openingOf($ana, 'SL'));
        $this->assertSame('2026-02-28', LeaveLedgerEntry::query()->where('employee_id', $ana->id)->first()->entry_date->toDateString());
    }

    public function test_as_of_ignores_later_months(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $sheet = $this->card('ANA B. REYES', 2026, [1 => [10, 20], 2 => [11, 21], 3 => [12, 22]]);

        $this->workbook->apply($this->workbook->check(['REYES' => $sheet], '2026-02'), 'cards.xlsx');

        $this->assertSame(11.0, $this->openingOf($ana, 'VL'));
        $this->assertSame(21.0, $this->openingOf($ana, 'SL'));
    }

    public function test_a_year_with_no_credits_yet_uses_the_balance_brought_forward(): void
    {
        $ana = $this->employee('Ana', 'Reyes');

        $this->workbook->apply($this->workbook->check(['REYES' => $this->card('ANA B. REYES', 2026, [], [30, 40])]), 'cards.xlsx');

        $this->assertSame(30.0, $this->openingOf($ana, 'VL'));
        $this->assertSame('2025-12-31', LeaveLedgerEntry::query()->where('employee_id', $ana->id)->first()->entry_date->toDateString());
    }

    public function test_the_newest_year_wins_whatever_order_the_blocks_come_in(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $old = $this->card('ANA B. REYES', 2025, [12 => [5, 6]]);
        $new = $this->card('ANA B. REYES', 2026, [1 => [7, 8]]);
        $sheet = $old + $this->shifted($new, count($old) + 1);

        $this->workbook->apply($this->workbook->check(['REYES' => $sheet]), 'cards.xlsx');

        $this->assertSame(7.0, $this->openingOf($ana, 'VL'));
    }

    public function test_a_card_shifted_one_column_right_with_a_surname_first_name_is_read(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $sheet = $this->card('NAME: REYES, ANA B.', 2026, [1 => [10, 20]], column: 1);

        $this->workbook->apply($this->workbook->check(['REYES' => $sheet]), 'cards.xlsx');

        $this->assertSame(10.0, $this->openingOf($ana, 'VL'));
        $this->assertSame(20.0, $this->openingOf($ana, 'SL'));
    }

    public function test_a_labelled_name_without_a_comma_is_read_in_either_order(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $ben = $this->employee('Ben', 'Cruz');

        $checked = $this->workbook->check([
            'REYES' => $this->card('NAME: ANA B. REYES', 2026, [1 => [10, 20]]),
            'CRUZ' => $this->card('NAME: CRUZ BEN D.', 2026, [1 => [30, 40]]),
        ]);
        $this->workbook->apply($checked, 'cards.xlsx');

        $this->assertSame(10.0, $this->openingOf($ana, 'VL'));
        $this->assertSame(30.0, $this->openingOf($ben, 'VL'));
    }

    public function test_a_hyphenated_surname_matches_by_either_part(): void
    {
        $ana = $this->employee('Ana', 'Reyes-Cruz');

        $this->workbook->apply($this->workbook->check(['REYES' => $this->card('NAME: REYES, ANA B.', 2026, [1 => [10, 20]])]), 'cards.xlsx');

        $this->assertSame(10.0, $this->openingOf($ana, 'VL'));
    }

    public function test_someone_who_already_has_ledger_entries_is_left_alone_and_rerunning_posts_nothing(): void
    {
        $ana = $this->employee('Ana', 'Reyes');
        $sheets = ['REYES' => $this->card('ANA B. REYES', 2026, [1 => [10, 20]])];

        $first = $this->workbook->check($sheets);
        $this->assertSame(2, $this->workbook->apply($first, 'cards.xlsx'));

        $again = $this->workbook->check($sheets);
        $this->assertFalse($again->first()['ready']);
        $this->assertSame(0, $this->workbook->apply($again, 'cards.xlsx'));
        $this->assertSame(2, LeaveLedgerEntry::query()->where('employee_id', $ana->id)->count());
    }

    public function test_sheets_that_cannot_be_posted_are_reported_and_nothing_is_saved_for_them(): void
    {
        $this->employee('Ana', 'Reyes');
        $sheets = [
            'time ref' => [1 => ['A' => 'Working hours']],
            'NOBODY' => $this->card('ZED Q. NOBODY', 2026, [1 => [10, 20]]),
            'EMPTY' => [],
            'BLANK' => [1 => ['A' => 'ANA B. REYES']],
            'REYES' => $this->card('ANA B. REYES', 2026, [1 => [-3, 20]]),
        ];

        $checked = $this->workbook->check($sheets)->keyBy('sheet');

        $this->assertFalse($checked->has('time ref'));
        $this->assertStringContainsString('No IMIS employee', $checked['NOBODY']['errors'][0]);
        $this->assertSame(['The sheet is empty.'], $checked['EMPTY']['warnings']);
        $this->assertStringContainsString('No leave card block', $checked['BLANK']['errors'][0]);
        $this->assertStringContainsString('VL balance is negative', $checked['REYES']['errors'][0]);
        $this->assertSame(0, $this->workbook->apply($checked->values(), 'cards.xlsx'));
        $this->assertSame(0, LeaveLedgerEntry::query()->count());
    }

    public function test_two_accounts_with_the_same_name_are_not_guessed(): void
    {
        $this->employee('Ana', 'Reyes');
        $this->employee('Ana', 'Reyes');

        $checked = $this->workbook->check(['REYES' => $this->card('ANA B. REYES', 2026, [1 => [10, 20]])]);

        $this->assertFalse($checked->first()['ready']);
        $this->assertStringContainsString('more than one account', $checked->first()['errors'][0]);
    }

    public function test_the_command_refuses_a_missing_file_and_a_bad_as_of(): void
    {
        $this->artisan('imis:import-leave-cards', ['file' => 'no-such-file.xlsx'])->assertFailed();

        $file = tempnam(sys_get_temp_dir(), 'lc');
        try {
            $this->artisan('imis:import-leave-cards', ['file' => $file, '--as-of' => '2026-13'])->assertFailed();
        } finally {
            unlink($file);
        }
    }

    public function test_catch_up_accrues_only_the_months_after_each_opening_and_is_safe_to_rerun(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5));
        $ana = $this->employee('Ana', 'Reyes');
        $ben = $this->employee('Ben', 'Cruz');
        $this->workbook->apply($this->workbook->check([
            'REYES' => $this->card('ANA B. REYES', 2026, [1 => [10, 20]]),
        ]), 'cards.xlsx');
        $outsider = $this->employee('Cy', 'Lim'); // Not on the workbook: untouched.

        $this->artisan('imis:leave-cards-catch-up', ['--apply' => true])->assertSuccessful();
        $this->artisan('imis:leave-cards-catch-up', ['--apply' => true])->assertSuccessful();

        $months = fn (Employee $employee) => $employee->leaveLedger()->where('kind', LedgerEntryKind::Accrual)
            ->where('leave_type_id', LeaveType::query()->where('code', 'VL')->value('id'))
            ->orderBy('period_month')->pluck('period_month')->all();
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10], $months($ana));
        $this->assertSame([], $months($ben));
        $this->assertSame([], $months($outsider));
    }

    public function test_catch_up_previews_without_saving_and_flags_a_month_counted_twice(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5));
        $ana = $this->employee('Ana', 'Reyes');
        $this->workbook->apply($this->workbook->check([
            'REYES' => $this->card('ANA B. REYES', 2026, [9 => [10, 20]]),
        ]), 'cards.xlsx');
        // Sept is on the card; the monthly run also accrued it.
        app(LeaveCredits::class)->accrueMonth(2026, 9, null, [$ana->id]);
        $before = LeaveLedgerEntry::query()->count();

        $this->artisan('imis:leave-cards-catch-up')
            ->expectsOutputToContain('September 2026 was accrued as well as in the card')
            ->assertSuccessful();

        $this->assertSame($before, LeaveLedgerEntry::query()->count());
    }

    private function employee(string $first, string $last): Employee
    {
        return Employee::factory()->for(User::factory()->state(['first_name' => $first, 'last_name' => $last]))->create();
    }

    private function openingOf(Employee $employee, string $code): float
    {
        return (float) LeaveLedgerEntry::query()
            ->where('employee_id', $employee->id)
            ->where('kind', LedgerEntryKind::Opening)
            ->where('leave_type_id', LeaveType::query()->where('code', $code)->value('id'))
            ->value('amount');
    }

    /**
     * One year's block as the workbook lays it out: name, year, PERIOD
     * heading, "Balance Brought Forward", then two rows a month (credits
     * earned, then the month-end balance in E for VL and I for SL).
     *
     * @param  array<int, array{0: float, 1: float}>  $months  month => [VL, SL] balance
     * @param  array{0: float, 1: float}  $forward
     * @param  int  $column  how many columns the whole card is shifted right
     * @return array<int, array<string, string>>
     */
    private function card(string $name, int $year, array $months, array $forward = [0, 0], int $column = 0): array
    {
        $at = fn (string $letter) => chr(ord($letter) + $column);
        $names = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

        $rows = [
            1 => [$at('A') => $name],
            2 => [$at('B') => (string) $year],
            3 => [$at('A') => 'PERIOD', $at('C') => 'VACATION LEAVE'],
            4 => [$at('C') => 'EARNED'],
            5 => [$at('A') => 'Balance Brought Forward from Dec. 31, '.($year - 1), $at('E') => (string) $forward[0], $at('I') => (string) $forward[1]],
        ];
        $line = 6;
        foreach ($months as $month => [$vl, $sl]) {
            $label = $names[$month];
            $rows[$line++] = [$at('A') => "{$label}. 1-28, {$year}", $at('C') => '1.25', $at('G') => '1.25'];
            $rows[$line++] = [$at('A') => "{$label}. 28, {$year}", $at('E') => (string) $vl, $at('I') => (string) $sl];
        }

        return $rows;
    }

    /**
     * Appends a block below an earlier one, moving its rows down.
     *
     * @param  array<int, array<string, string>>  $block
     * @return array<int, array<string, string>>
     */
    private function shifted(array $block, int $start): array
    {
        $moved = [];
        foreach ($block as $number => $cells) {
            $moved[$start + $number - 1] = $cells;
        }

        return $moved;
    }
}
