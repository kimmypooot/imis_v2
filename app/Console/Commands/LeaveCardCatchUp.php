<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Enums\LedgerEntryKind;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Support\Audit\AuditLogger;
use App\Support\HR\Leave\LeaveCardWorkbook;
use App\Support\HR\Leave\LeaveCredits;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * After `imis:import-leave-cards`: each person's opening balance stops at
 * their card's last month, so the months since need accruing. Posts the
 * normal monthly accrual for every month after the opening's month up to
 * last month, for only the people imported from the workbook. Months
 * already accrued are skipped, so re-running is safe.
 *
 * An opening that already covers a month with an accrual (the monthly run
 * went ahead of the import) would count that month twice; those people
 * are listed to be corrected by hand, never changed here.
 */
class LeaveCardCatchUp extends Command
{
    protected $signature = 'imis:leave-cards-catch-up
        {--apply : Post the accruals (without it, only shows what would be posted)}';

    protected $description = 'Accrue the months since each leave-card opening balance';

    public function handle(LeaveCredits $credits, AuditLogger $audit): int
    {
        $last = today()->subMonthNoOverflow()->startOfMonth();
        $openings = LeaveLedgerEntry::query()
            ->where('kind', LedgerEntryKind::Opening)
            ->where('particulars', 'like', LeaveCardWorkbook::PARTICULARS.'%')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($entries) => $entries->max('entry_date'));

        $employees = Employee::query()->with('user')->whereIn('id', $openings->keys())->get()->keyBy('id');
        $plan = [];
        $double = [];

        foreach ($openings as $employeeId => $openedOn) {
            $employee = $employees->get($employeeId);
            if ($employee->isSeparated()) {
                continue;
            }
            $openedOn = Carbon::parse($openedOn);

            $accrued = $employee->leaveLedger()->where('kind', LedgerEntryKind::Accrual)
                ->get(['period_year', 'period_month'])
                ->map(fn ($entry) => Carbon::create($entry->period_year, $entry->period_month, 1));

            if ($accrued->contains(fn (Carbon $month) => $month->equalTo($openedOn->copy()->startOfMonth()))) {
                $double[] = "{$employee->user->name}: {$openedOn->format('F Y')} was accrued as well as in the card's balance.";
            }

            $months = [];
            for ($month = $openedOn->copy()->addMonthNoOverflow()->startOfMonth(); $month->lte($last); $month->addMonthNoOverflow()) {
                if (! $accrued->contains(fn (Carbon $done) => $done->equalTo($month))) {
                    $months[] = $month->copy();
                }
            }
            if ($months !== []) {
                $plan[$employeeId] = $months;
            }
        }

        foreach ($plan as $employeeId => $months) {
            $this->line(sprintf('%s: %s', $employees[$employeeId]->user->name, collect($months)->map->format('M Y')->implode(', ')));
        }
        foreach ($double as $line) {
            $this->warn("! {$line} Reverse the extra accrual from the leave card page.");
        }

        $total = collect($plan)->sum(fn (array $months) => count($months));
        $this->newLine();
        $this->line(sprintf('%d people with %d months to accrue; %d with a month counted twice.', count($plan), $total, count($double)));

        if (! $this->option('apply')) {
            $this->info('Nothing was saved; run with --apply to post the accruals.');

            return self::SUCCESS;
        }

        $posted = 0;
        foreach (collect($plan)->flatMap(fn (array $months, int $id) => collect($months)->map(fn (Carbon $month) => [$month, $id]))->groupBy(fn (array $pair) => $pair[0]->format('Y-m')) as $key => $pairs) {
            [$year, $month] = array_map('intval', explode('-', $key));
            $posted += $credits->accrueMonth($year, $month, null, $pairs->pluck(1)->all())['posted'];
        }

        $audit->recordFor(null, AuditEvent::LeaveAccrualRun, 'Caught up leave accrual after the leave card import', null, ['people' => count($plan), 'entries' => $posted]);
        $this->info("Saved: {$posted} accrual entries for ".count($plan).' people.');

        return self::SUCCESS;
    }
}
