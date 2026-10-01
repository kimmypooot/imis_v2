<?php

namespace App\Support\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The only writer of audit_logs. Each call is a single INSERT with no
 * extra reads (labels come from models already in memory), so auditing an
 * action costs one small write.
 *
 * Who did it always comes from the server: the authenticated user on the
 * current guard. Callers can't pass an actor — the one exception is
 * `recordFor()`, used by the authentication listeners, where the guard
 * itself hands over the user it is logging in or out.
 *
 * Nothing secret is ever stored: metadata is built by callers from
 * explicit, known fields (never a raw request body), and is then scrubbed
 * of any key that looks like a credential and has long values truncated,
 * as a second line of defense.
 */
class AuditLogger
{
    /**
     * Metadata keys whose values are dropped wherever they appear.
     */
    private const SENSITIVE_KEY_PATTERN = '/password|passwd|secret|token|remember_token|session|api[_-]?key|credential|authorization|cookie/i';

    /**
     * Attributes never worth recording as a "change".
     */
    private const IGNORED_ATTRIBUTES = ['created_at', 'updated_at', 'password', 'remember_token', 'email_verified_at'];

    private const MAX_VALUE_LENGTH = 255;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(AuditEvent $event, string $description, ?Model $target = null, array $metadata = []): AuditLog
    {
        return $this->write(Auth::user(), $event, $description, $target, $metadata);
    }

    /**
     * For authentication events, where the actor is the user the guard is
     * logging in/out (not yet, or no longer, `Auth::user()`) — or null for
     * an unauthenticated failed attempt.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordFor(?User $actor, AuditEvent $event, string $description, ?Model $target = null, array $metadata = []): AuditLog
    {
        return $this->write($actor, $event, $description, $target, $metadata);
    }

    /**
     * Records the attribute changes from the model's last save() as
     * `metadata.changes` (`{field: {from, to}}`). Records nothing when the
     * save changed nothing, so re-submitting an unchanged form adds no noise.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordChanges(AuditEvent $event, string $description, Model $target, array $metadata = []): ?AuditLog
    {
        $changes = $this->changes($target);

        if ($changes === []) {
            return null;
        }

        return $this->record($event, $description, $target, ['changes' => $changes, ...$metadata]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function write(?User $actor, AuditEvent $event, string $description, ?Model $target, array $metadata): AuditLog
    {
        $metadata = $this->sanitize($metadata);
        // Resolved per write, not injected: listeners hold this logger from
        // boot, before the request being handled exists.
        $request = app(Request::class);

        $log = new AuditLog;
        $log->forceFill([
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor ? Str::limit("{$actor->name} <{$actor->email}>", 250) : null,
            'event' => $event,
            'action' => $event->action(),
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'target_label' => $target ? Str::limit(static::labelFor($target), 250) : null,
            'description' => Str::limit($description, 495),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, '') ?: null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
        $log->save();

        return $log;
    }

    public static function labelFor(Model $model): string
    {
        foreach (['name', 'title', 'label', 'position', 'email'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function changes(Model $model): array
    {
        $previous = $model->getPrevious();

        return collect($model->getChanges())
            ->except(self::IGNORED_ATTRIBUTES)
            ->map(fn ($value, $key) => ['from' => $previous[$key] ?? null, 'to' => $value])
            ->all();
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sanitize(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $this->sanitize($value),
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                is_string($value) => Str::limit($value, self::MAX_VALUE_LENGTH),
                is_scalar($value), $value === null => $value,
                default => Str::limit((string) json_encode($value), self::MAX_VALUE_LENGTH),
            };
        }

        return $clean;
    }
}
