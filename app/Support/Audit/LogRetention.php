<?php

namespace App\Support\Audit;

use App\Enums\AuditEvent;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes log rows older than their retention period (System Settings →
 * Log Retention). Run nightly by `imis:prune-logs`.
 *
 * This is the one place audit entries are ever deleted. It goes through
 * the query builder on purpose: App\Models\AuditLog refuses deletion so
 * that no request can remove an entry, while retention is a policy the
 * administrator sets, and every run that deletes something is itself
 * recorded. Each period has a floor (the setting's `min_unless_zero`),
 * applied here too, so a value below it can't slip in another way.
 */
class LogRetention
{
    private const CHUNK = 5000;

    /**
     * @return array<string, array{setting: string, table: string, column: string, scope: ?callable}>
     */
    private function policies(): array
    {
        $personal = array_map(fn (AuditEvent $event) => $event->value, AuditEvent::personalDataAccess());

        return [
            'login_history' => [
                'setting' => 'retention_login_history_months',
                'table' => 'login_histories',
                'column' => 'logged_in_at',
                'scope' => null,
            ],
            'system_access' => [
                'setting' => 'retention_system_access_months',
                'table' => 'system_access_logs',
                'column' => 'accessed_at',
                'scope' => null,
            ],
            'audit' => [
                'setting' => 'retention_audit_log_months',
                'table' => 'audit_logs',
                'column' => 'created_at',
                'scope' => fn (Builder $query) => $query->whereNotIn('event', $personal),
            ],
            'personal_data_access' => [
                'setting' => 'retention_personal_data_access_months',
                'table' => 'audit_logs',
                'column' => 'created_at',
                'scope' => fn (Builder $query) => $query->whereIn('event', $personal),
            ],
        ];
    }

    /**
     * What each run would delete before: the cutoff per log, or null when
     * that log is kept forever.
     *
     * @return array<string, ?Carbon>
     */
    public function cutoffs(): array
    {
        return collect($this->policies())
            ->map(fn (array $policy) => ($months = $this->months($policy['setting'])) ? now()->subMonths($months) : null)
            ->all();
    }

    /**
     * Rows that would be deleted now, per log.
     *
     * @return array<string, int>
     */
    public function preview(): array
    {
        $cutoffs = $this->cutoffs();

        return collect($this->policies())
            ->map(fn (array $policy, string $key) => $cutoffs[$key] ? $this->query($policy, $cutoffs[$key])->count() : 0)
            ->all();
    }

    /**
     * @return array<string, int> rows deleted, per log
     */
    public function prune(): array
    {
        $cutoffs = $this->cutoffs();
        $deleted = [];

        foreach ($this->policies() as $key => $policy) {
            $deleted[$key] = 0;

            if (! $cutoffs[$key]) {
                continue;
            }

            // In chunks, so a first run over years of rows doesn't hold a
            // long lock on a table every sign-in writes to.
            do {
                $ids = $this->query($policy, $cutoffs[$key])->orderBy('id')->limit(self::CHUNK)->pluck('id');
                $count = $ids->isEmpty() ? 0 : DB::table($policy['table'])->whereIn('id', $ids)->delete();
                $deleted[$key] += $count;
            } while ($count === self::CHUNK);
        }

        return $deleted;
    }

    private function query(array $policy, Carbon $cutoff): Builder
    {
        $query = DB::table($policy['table'])->where($policy['column'], '<', $cutoff);

        return $policy['scope'] ? ($policy['scope'])($query) : $query;
    }

    /** 0 = keep forever; otherwise never below the setting's floor. */
    private function months(string $setting): int
    {
        $months = (int) Settings::get($setting);
        $floor = (int) (SettingRegistry::definitions()[$setting]['constraints']['min_unless_zero'] ?? 1);

        return $months > 0 ? max($months, $floor) : 0;
    }
}
