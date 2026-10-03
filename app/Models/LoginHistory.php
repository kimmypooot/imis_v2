<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One sign-in session (legacy imis_history_login_logs). Written only by
 * App\Support\Security\LoginHistoryRecorder; never edited from the UI.
 */
class LoginHistory extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logged_in_at' => 'datetime',
            'logged_out_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * active (session still alive) · logged_out · ended (by an admin) ·
     * expired (timed out or closed without signing out).
     *
     * `ended_by`: user · admin · timeout (idle or absolute limit, closed by
     * EnforceSessionLimits or imis:expire-sessions). A legacy row with a
     * sign-out time but no `ended_by` was a legacy timeout.
     */
    public function statusFor(bool $sessionAlive): string
    {
        return match (true) {
            $this->ended_by === 'admin' => 'ended',
            $this->ended_by === 'user' => 'logged_out',
            $this->logged_out_at === null && $sessionAlive => 'active',
            default => 'expired',
        };
    }

    /**
     * Ids of sessions that are still alive (database session driver).
     *
     * @param  array<int, string>  $sessionIds
     * @return array<int, string>
     */
    public static function liveSessionIds(array $sessionIds): array
    {
        if ($sessionIds === [] || config('session.driver') !== 'database') {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))
            ->whereIn('id', $sessionIds)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->pluck('id')
            ->all();
    }
}
