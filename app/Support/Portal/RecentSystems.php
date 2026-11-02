<?php

namespace App\Support\Portal;

use App\Models\SystemAccessLog;
use App\Models\User;

/**
 * The systems a person opened most recently through IMIS, newest first,
 * read from the launch records IMIS already keeps (System Access Logs).
 * Feeds the dashboard's "Your systems" row; nothing extra is recorded.
 */
class RecentSystems
{
    /**
     * @return array<int, int> application ids, most recently opened first
     */
    public function idsFor(User $user, int $limit = 8): array
    {
        return SystemAccessLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('application_id')
            ->selectRaw('application_id, MAX(accessed_at) as last_opened')
            ->groupBy('application_id')
            ->orderByDesc('last_opened')
            ->limit($limit)
            ->pluck('application_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
