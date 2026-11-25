<?php

namespace App\Console\Commands;

use App\Enums\SalnType;
use App\Notifications\HrUpdate;
use App\Support\HR\Saln\SalnObligations;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Reminds employees of a SALN they still have to file: once it is due to
 * be filed, a week before the deadline, and on the deadline. Each stage is
 * sent once per filing, so re-running never repeats a reminder.
 */
class SendSalnReminders extends Command
{
    protected $signature = 'imis:saln-reminders';

    protected $description = 'Remind employees of SALNs they still have to file';

    public function handle(SalnObligations $obligations): int
    {
        $today = CarbonImmutable::today();
        $sent = 0;

        foreach ($obligations->open() as $row) {
            $user = $row['employee']->user;
            if (! $user?->isActive()) {
                continue;
            }

            $stage = match (true) {
                $today->isSameDay($row['due']) => 'due',
                $today->isSameDay($row['due']->subDays(7)) => 'week',
                $today->lt($row['due']) => 'start',
                default => null,
            };

            $key = "saln-reminder:{$row['employee']->id}:{$row['type']->value}:{$row['as_of']->toDateString()}:{$stage}";
            if ($stage === null || ! Cache::add($key, true, now()->addDays(400))) {
                continue;
            }

            $user->notify(new HrUpdate('SALN reminder', $this->message($row['type'], $row['as_of'], $row['due'], $stage), url('/my/saln')));
            $sent++;
        }

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }

    private function message(SalnType $type, CarbonImmutable $asOf, CarbonImmutable $due, string $stage): string
    {
        $what = match ($type) {
            SalnType::Annual => "your annual SALN as of December 31, {$asOf->year}",
            SalnType::Entry => 'your SALN upon assumption of office as of '.$asOf->format('F j, Y'),
            SalnType::Exit => 'your exit SALN as of '.$asOf->format('F j, Y'),
        };

        return match ($stage) {
            'due' => "Today is the last day to file {$what}.",
            'week' => "One week left to file {$what} (due {$due->format('F j, Y')}).",
            default => "Please file {$what} by {$due->format('F j, Y')}.",
        };
    }
}
