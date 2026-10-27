<?php

namespace App\Console\Commands;

use App\Models\EmployeeLoan;
use App\Support\HR\Payroll\DeductionNotices;
use App\Support\HR\Payroll\Loans;
use Illuminate\Console\Command;

/**
 * HR-8: tells employees when a recurring deduction is nearly finished
 * (Loans::NEARLY_PAID_MONTHS or fewer deductions left). Each loan is told
 * once per count, so re-running never repeats a notice.
 */
class NotifyLoanEndings extends Command
{
    protected $signature = 'imis:notify-loan-endings';

    protected $description = 'Tell employees their recurring deductions are nearly completed';

    public function handle(Loans $loans, DeductionNotices $notices): int
    {
        $sent = 0;

        $active = EmployeeLoan::query()->with(['employee.user', 'component'])->whereNull('stopped_on')->get();
        foreach ($loans->presentMany($active) as $row) {
            $left = $row['months_left'];
            if ($row['status'] !== 'active' || $left === null || $left < 1 || $left > Loans::NEARLY_PAID_MONTHS) {
                continue;
            }

            if ($notices->endingSoon($active->firstWhere('id', $row['id']), $left)) {
                $sent++;
            }
        }

        $this->info("{$sent} notice(s) sent.");

        return self::SUCCESS;
    }
}
