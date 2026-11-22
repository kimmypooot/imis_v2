<?php

namespace App\Console\Commands;

use App\Support\HR\Leave\LeaveApplications;
use Illuminate\Console\Command;

/**
 * Sec. 49, Rule XVI of the Omnibus Rules on Leave: an application not
 * acted on within five working days of receipt is deemed approved. Each
 * morning, whoever must act (and HR) is told on the last day, and again
 * once the days have passed. Each application is told once per stage.
 */
class LeaveDeadlines extends Command
{
    protected $signature = 'imis:leave-deadlines';

    protected $description = 'Remind approvers of leave applications nearing or past the five working days to act';

    public function handle(LeaveApplications $applications): int
    {
        $this->info($applications->remindOverdue().' reminder(s) sent.');

        return self::SUCCESS;
    }
}
