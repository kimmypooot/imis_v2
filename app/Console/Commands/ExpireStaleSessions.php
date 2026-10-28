<?php

namespace App\Console\Commands;

use App\Models\LoginHistory;
use App\Support\Legacy\SsoLogout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Closes login history for sessions that ended without a sign-out —
 * idle-expired or abandoned (legacy inc/session_cleanup.php). Scheduled
 * every five minutes in routes/console.php. Needs the database session
 * driver, the only one whose sessions can be looked up.
 */
class ExpireStaleSessions extends Command
{
    protected $signature = 'imis:expire-sessions';

    protected $description = 'Mark sign-ins whose session has expired as timed out in the login history';

    public function handle(SsoLogout $systems): int
    {
        if (config('session.driver') !== 'database') {
            $this->warn('Skipped: only the database session driver can be checked.');

            return self::SUCCESS;
        }

        $table = config('session.table', 'sessions');
        $idleSeconds = (int) config('session.lifetime') * 60;
        $closed = 0;

        LoginHistory::query()
            ->whereNull('logged_out_at')
            ->whereNotNull('session_id')
            ->chunkById(500, function ($logins) use ($table, $idleSeconds, $systems, &$closed) {
                $lastActivity = DB::table($table)
                    ->whereIn('id', $logins->pluck('session_id'))
                    ->pluck('last_activity', 'id');

                foreach ($logins as $login) {
                    $last = $lastActivity[$login->session_id] ?? null;

                    if ($last !== null && $last + $idleSeconds > now()->getTimestamp()) {
                        continue;
                    }

                    // Ended when it went idle; if the row is already gone, now is the best we know.
                    $endedAt = $last !== null ? now()->setTimestamp($last + $idleSeconds) : now();

                    $systems->signOut($login->session_id);

                    LoginHistory::query()->whereKey($login->id)->whereNull('logged_out_at')
                        ->update(['logged_out_at' => $endedAt, 'ended_by' => 'timeout']);
                    $closed++;
                }
            });

        $this->info("Closed {$closed} expired sign-in(s).");

        return self::SUCCESS;
    }
}
