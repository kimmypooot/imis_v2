<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Application\StoreApplicationRoleRequest;
use App\Http\Requests\Application\UpdateApplicationRoleRequest;
use App\Http\Requests\Application\UpdateApplicationRoleStatusRequest;
use App\Models\Application;
use App\Models\ApplicationRole;
use App\Support\SystemAccess\OfficeAdminAccess;
use Illuminate\Http\RedirectResponse;

/**
 * Defines the roles that exist inside one system. No hard delete —
 * deactivation only, and only once nobody holds the role.
 */
class ApplicationRoleController extends Controller
{
    public function store(StoreApplicationRoleRequest $request, Application $application): RedirectResponse
    {
        $role = $application->roles()->create($request->validated());

        $this->audit()->record(
            AuditEvent::ApplicationRoleCreated,
            "Added role {$role->name} to system {$application->name}",
            $application,
            ['role_id' => $role->id, 'role' => $role->name],
        );

        // A new admin role: the office's Division Chief / Field Director gets it.
        app(OfficeAdminAccess::class)->syncApplication($application);

        return back()->with('status', "Role {$role->name} added.");
    }

    public function update(UpdateApplicationRoleRequest $request, Application $application, ApplicationRole $applicationRole): RedirectResponse
    {
        $this->abortUnlessBelongsToApplication($application, $applicationRole);

        $applicationRole->update($request->validated());

        $this->recordRoleChanges(
            AuditEvent::ApplicationRoleUpdated,
            "Updated role {$applicationRole->name} of system {$application->name}",
            $application,
            $applicationRole,
        );

        app(OfficeAdminAccess::class)->syncApplication($application);

        return back()->with('status', "Role {$applicationRole->name} saved.");
    }

    public function updateStatus(
        UpdateApplicationRoleStatusRequest $request,
        Application $application,
        ApplicationRole $applicationRole,
    ): RedirectResponse {
        $this->abortUnlessBelongsToApplication($application, $applicationRole);

        $applicationRole->update($request->validated());

        $this->recordRoleChanges(
            AuditEvent::ApplicationRoleStatusChanged,
            "{$this->activationVerb($applicationRole->status)} role {$applicationRole->name} of system {$application->name}",
            $application,
            $applicationRole,
        );

        app(OfficeAdminAccess::class)->syncApplication($application);

        return back()->with('status', "Role {$applicationRole->name} ".strtolower($this->activationVerb($applicationRole->status)).'.');
    }

    /**
     * Role events target the application, so a system's configuration and
     * access history read as one timeline.
     */
    private function recordRoleChanges(AuditEvent $event, string $description, Application $application, ApplicationRole $role): void
    {
        $changes = $this->audit()->changes($role);

        if ($changes === []) {
            return;
        }

        $this->audit()->record($event, $description, $application, [
            'role_id' => $role->id,
            'role' => $role->name,
            'changes' => $changes,
        ]);
    }

    private function abortUnlessBelongsToApplication(Application $application, ApplicationRole $role): void
    {
        abort_unless($role->application_id === $application->id, 404);
    }
}
