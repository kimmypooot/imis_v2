<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Application\GrantApplicationAccessRequest;
use App\Http\Requests\Application\RevokeApplicationAccessRequest;
use App\Http\Requests\Application\UpdateApplicationAccessRequest;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Support\SystemAccess\SystemAccessManager;
use Illuminate\Http\RedirectResponse;

/**
 * Single-grant actions on one system (grant, change role, revoke). The
 * admin UI edits access in bulk instead (SystemAccessController and
 * UserSystemAccessController); these remain for direct, one-off changes.
 */
class ApplicationAccessController extends Controller
{
    public function __construct(private readonly SystemAccessManager $access) {}

    public function store(GrantApplicationAccessRequest $request, Application $application): RedirectResponse
    {
        $this->access->grant(
            $application,
            $request->targetUser(),
            ApplicationRole::query()->findOrFail($request->validated('application_role_id')),
        );

        return back()->with('status', 'Access granted.');
    }

    public function update(UpdateApplicationAccessRequest $request, Application $application, ApplicationAccess $access): RedirectResponse
    {
        $this->abortUnlessBelongsToApplication($application, $access);

        $this->access->change($access, ApplicationRole::query()->findOrFail($request->validated('application_role_id')));

        return back()->with('status', 'Role changed.');
    }

    public function destroy(RevokeApplicationAccessRequest $request, Application $application, ApplicationAccess $access): RedirectResponse
    {
        $this->abortUnlessBelongsToApplication($application, $access);

        $this->access->revoke($access);

        return back()->with('status', 'Access revoked.');
    }

    private function abortUnlessBelongsToApplication(Application $application, ApplicationAccess $access): void
    {
        abort_unless($access->application_id === $application->id, 404);
    }
}
