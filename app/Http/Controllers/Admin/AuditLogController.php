<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tables\ServerTable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only audit trail viewer. Every filter is an equality/range on an
 * indexed column (see the audit_logs migration), except the free-text
 * search, which is a LIKE on description/labels.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        $logs = AuditLog::query()
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhere('target_label', 'like', "%{$search}%")
                        ->orWhere('actor_label', 'like', "%{$search}%");
                });
            })
            ->when(
                AuditEvent::tryFrom($request->string('event')->value()),
                fn ($query, AuditEvent $event) => $query->where('event', $event),
            )
            ->when($request->integer('actor_id') ?: null, fn ($query, $actorId) => $query->where('actor_id', $actorId))
            ->when(
                $this->targetType($request->string('target_type')->value()),
                fn ($query, $type) => $query->where('target_type', $type),
            )
            ->when($request->integer('target_id') ?: null, fn ($query, $targetId) => $query->where('target_id', $targetId))
            ->when($from, fn ($query, Carbon $from) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query, Carbon $to) => $query->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(in_array($request->integer('per_page'), ServerTable::PAGE_SIZES, true) ? $request->integer('per_page') : 25, ['id', 'actor_id', 'actor_label', 'event', 'target_type', 'target_id', 'target_label', 'description', 'ip_address', 'created_at'])
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event->value,
                'event_label' => $log->event->label(),
                'description' => $log->description,
                'actor_id' => $log->actor_id,
                'actor_label' => $log->actor_label,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'target_label' => $log->target_label,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => $request->only(['search', 'event', 'actor_id', 'target_type', 'target_id', 'from', 'to']),
            'eventOptions' => AuditEvent::options(),
            // Only users who have actually done something auditable — not
            // every account — keeps this list (and its query) small.
            'actorOptions' => User::query()
                ->whereIn('id', AuditLog::query()->select('actor_id')->whereNotNull('actor_id')->distinct())
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => "{$user->name} ({$user->email})"]),
            'targetTypeOptions' => collect(Relation::morphMap())
                ->keys()
                ->map(fn (string $type) => ['value' => $type, 'label' => str($type)->replace('_', ' ')->headline()->value()])
                ->values(),
        ]);
    }

    public function show(AuditLog $auditLog): Response
    {
        $this->authorize('view', $auditLog);

        return Inertia::render('Admin/AuditLogs/Show', [
            'log' => [
                'id' => $auditLog->id,
                'event' => $auditLog->event->value,
                'event_label' => $auditLog->event->label(),
                'action' => $auditLog->action,
                'description' => $auditLog->description,
                'actor_id' => $auditLog->actor_id,
                'actor_label' => $auditLog->actor_label,
                'target_type' => $auditLog->target_type,
                'target_id' => $auditLog->target_id,
                'target_label' => $auditLog->target_label,
                'ip_address' => $auditLog->ip_address,
                'user_agent' => $auditLog->user_agent,
                'metadata' => $auditLog->metadata,
                'created_at' => $auditLog->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Only known morph aliases are accepted, so the filter can't be used to
     * probe arbitrary class names.
     */
    private function targetType(string $type): ?string
    {
        return array_key_exists($type, Relation::morphMap()) ? $type : null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
