<?php

namespace App\Console\Commands;

use App\Support\System\HealthChecks;
use Illuminate\Console\Command;

/**
 * The System Health checks from the command line — for the deploy script
 * or an external monitor. Exits non-zero when any check fails (or, with
 * --strict, needs attention).
 */
class CheckHealth extends Command
{
    protected $signature = 'imis:health
        {--strict : Also exit non-zero when a check only needs attention}';

    protected $description = 'Check that everything the IMIS depends on is in place and working';

    public function handle(HealthChecks $health): int
    {
        $checks = $health->run();

        $this->table(['', 'Area', 'Check', 'Result'], array_map(fn (array $check) => [
            ['ok' => '<info>OK</info>', 'warn' => '<comment>CHECK</comment>', 'fail' => '<error>FAIL</error>'][$check['status']],
            $check['group'], $check['label'], $check['detail'],
        ], $checks));

        $overall = HealthChecks::overall($checks);
        $failed = $overall === 'fail' || ($overall === 'warn' && $this->option('strict'));

        $failed ? $this->error('Some checks need fixing.') : $this->info($overall === 'ok' ? 'All checks passed.' : 'Working, with items to look at.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
