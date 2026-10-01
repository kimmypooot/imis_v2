<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Support\Audit\AuditLogger;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    protected function audit(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    /**
     * "Activated"/"Deactivated" for any active/inactive status enum
     * (Status, UserStatus), for audit descriptions.
     */
    protected function activationVerb(BackedEnum $status): string
    {
        return $status->value === 'active' ? 'Activated' : 'Deactivated';
    }

    /**
     * Audits a many-to-many sync() (role ↔ user, permission ↔ role) as
     * names added/removed. Nothing is recorded when the sync was a no-op.
     *
     * @param  class-string<Model>  $relatedModel
     * @param  array{attached: array<int, int>, detached: array<int, int>}  $syncResult
     */
    protected function recordPivotChange(
        AuditEvent $event,
        string $description,
        Model $target,
        string $relatedModel,
        array $syncResult,
    ): void {
        if ($syncResult['attached'] === [] && $syncResult['detached'] === []) {
            return;
        }

        $names = fn (array $ids) => $relatedModel::query()->whereKey($ids)->orderBy('name')->pluck('name')->all();

        $this->audit()->record($event, $description, $target, [
            'added' => $names($syncResult['attached']),
            'removed' => $names($syncResult['detached']),
        ]);
    }
}
