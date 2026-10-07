<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\HR\Leave\LeaveCredits;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * HR-6a: posts a month's leave accrual (default: last month), and on
 * January's run also the year start (lapses and annual grants). Scheduled
 * on the 1st of each month; re-running never posts twice.
 */
class LeaveAccrual extends Command
{
    protected $signature = 'imis:leave-accrual {--year=} {--month=}';

    protected $description = 'Post monthly leave accrual (and the year start in January)';

    public function handle(LeaveCredits $credits, AuditLogger $audit): int
    {
        $lastMonth = today()->subMonthNoOverflow();
        $year = (int) ($this->option('year') ?: $lastMonth->year);
        $month = (int) ($this->option('month') ?: $lastMonth->month);

        if ($month < 1 || $month > 12 || Carbon::create($year, $month, 1)->gt(today()->startOfMonth())) {
            $this->error('Give a month that has started (1–12).');

            return self::FAILURE;
        }

        $result = $credits->accrueMonth($year, $month);
        $audit->recordFor(null, AuditEvent::LeaveAccrualRun, 'Ran leave accrual for '.Carbon::create($year, $month, 1)->format('F Y').' (scheduled)', null, compact('year', 'month') + $result);
        $this->info("Accrual {$year}-{$month}: {$result['posted']} credited, {$result['already']} already done, {$result['not_eligible']} not eligible.");

        // The first run of a new year also starts the year.
        if (! $this->option('year') && today()->month === 1) {
            $start = $credits->startYear((int) today()->year);
            $audit->recordFor(null, AuditEvent::LeaveYearStartRun, 'Ran the leave year start for '.today()->year.' (scheduled)', null, ['year' => today()->year] + $start);
            $this->info("Year start: {$start['granted']} grants, {$start['lapsed']} lapsed.");
        }

        // Annual grants for anyone who joined (or became eligible) since
        // January 1, or a leave type switched on mid-year.
        if (! $this->option('year')) {
            $due = $credits->grantDue();
            if ($due['granted'] > 0) {
                $audit->recordFor(null, AuditEvent::LeaveYearStartRun, "Posted {$due['granted']} annual leave grant(s) due since January 1 (scheduled)", null, ['year' => today()->year] + $due);
            }
            $this->info("Grants due this year: {$due['granted']} posted.");
        }

        return self::SUCCESS;
    }
}
