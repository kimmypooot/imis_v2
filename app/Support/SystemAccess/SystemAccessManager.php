<?php

namespace App\Support\SystemAccess;

use App\Enums\AuditEvent;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;

/**
 * The single place a user's per-system access is written, so both admin
 * screens (per system and per user) apply and audit changes identically.
 * Callers have already authorized and validated the change — this only
 * applies it.
 *
 * Every event targets the application, so a system's access history reads
 * as one timeline, with the affected user and role names in metadata.
 */
class SystemAccessManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Sets the user's role in the system: grants, changes, or (with a null
     * role) revokes. A no-op when nothing would change.
     */
    public function set(Application $application, User $user, ?ApplicationRole $role): void
    {
        $access = $application->accessGrants()->with('role')->where('user_id', $user->id)->first();

        match (true) {
            $access === null && $role === null => null,
            $access === null => $this->grant($application, $user, $role),
            $role === null => $this->revoke($access),
            default => $this->change($access, $role),
        };
    }

    public function grant(Application $application, User $user, ApplicationRole $role): ApplicationAccess
    {
        $access = $application->accessGrants()->make([
            'user_id' => $user->id,
            'application_role_id' => $role->id,
        ]);
        $access->forceFill(['granted_by' => Auth::id()])->save();

        $this->record(
            AuditEvent::ApplicationAccessGranted,
            "Granted {$user->name} access to {$application->name} as {$role->name}",
            $application,
            $user,
            ['role' => $role->name],
        );

        return $access;
    }

    public function change(ApplicationAccess $access, ApplicationRole $role): void
    {
        $previous = $access->role;

        if ($previous->is($role)) {
            return;
        }

        $access->forceFill(['application_role_id' => $role->id, 'granted_by' => Auth::id()])->save();
        $access->setRelation('role', $role);

        $this->record(
            AuditEvent::ApplicationAccessChanged,
            "Changed {$access->user->name}'s role in {$access->application->name} from {$previous->name} to {$role->name}",
            $access->application,
            $access->user,
            ['from_role' => $previous->name, 'to_role' => $role->name],
        );
    }

    public function revoke(ApplicationAccess $access): void
    {
        $access->delete();

        $this->record(
            AuditEvent::ApplicationAccessRevoked,
            "Revoked {$access->user->name}'s access to {$access->application->name}",
            $access->application,
            $access->user,
            ['role' => $access->role->name],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(AuditEvent $event, string $description, Application $application, User $user, array $metadata): void
    {
        $this->audit->record($event, $description, $application, [
            'user_id' => $user->id,
            'user_name' => $user->name,
            ...$metadata,
        ]);
    }
}
