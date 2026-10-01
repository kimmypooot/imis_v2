<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Application\GrantApplicationAccessRequest;
use App\Http\Requests\Application\RevokeApplicationAccessRequest;
use App\Http\Requests\Application\UpdateApplicationAccessRequest;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\User;
use App\Support\SystemAccess\SystemAccessManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The per-system access screen: the roles that exist inside one system
 * and who holds each. The per-user counterpart is
 * UserSystemAccessController.
 */
class ApplicationAccessController extends Controller
{
    public function __construct(private readonly SystemAccessManager $access) {}

    public function show(Application $application): Response
    {
        $this->authorize('view', $application);

        $grants = $application->accessGrants()
            ->with(['user:id,name,email,status', 'role:id,name', 'grantedBy:id,name'])
            ->get()
            ->sortBy(fn (ApplicationAccess $access) => $access->user->name)
            ->values();

        return Inertia::render('Admin/Applications/Access', [
            'application' => $application->only(['id', 'name', 'short_name', 'url', 'requires_access', 'under_maintenance', 'status']),
            'roles' => $application->roles()->withCount('accessGrants')->get(),
            'grants' => $grants->map(fn (ApplicationAccess $access) => [
                'id' => $access->id,
                'user' => $access->user->only(['id', 'name', 'email', 'status']),
                'role' => $access->role->only(['id', 'name']),
                'granted_by' => $access->grantedBy?->name,
                'updated_at' => $access->updated_at?->toIso8601String(),
            ]),
            'userOptions' => User::query()
                ->where('status', 'active')
                ->whereNotIn('id', $grants->pluck('user.id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'statusOptions' => Status::options(),
        ]);
    }

    public function store(GrantApplicationAccessRequest $request, Application $application): RedirectResponse
    {
        $this->access->grant(
            $application,
            $request->targetUser(),
            ApplicationRole::query()->findOrFail($request->validated('application_role_id')),
        );

        return to_route('admin.applications.access', $application);
    }

    public function update(UpdateApplicationAccessRequest $request, Application $application, ApplicationAccess $access): RedirectResponse
    {
        $this->abortUnlessBelongsToApplication($application, $access);

        $this->access->change($access, ApplicationRole::query()->findOrFail($request->validated('application_role_id')));

        return to_route('admin.applications.access', $application);
    }

    public function destroy(RevokeApplicationAccessRequest $request, Application $application, ApplicationAccess $access): RedirectResponse
    {
        $this->abortUnlessBelongsToApplication($application, $access);

        $this->access->revoke($access);

        return to_route('admin.applications.access', $application);
    }

    private function abortUnlessBelongsToApplication(Application $application, ApplicationAccess $access): void
    {
        abort_unless($access->application_id === $application->id, 404);
    }
}
