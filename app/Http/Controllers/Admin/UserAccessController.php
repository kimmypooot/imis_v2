<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use App\Support\SystemAccess\AccessMatrix;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The legacy "User Access" page: a table of users; "Manage Access" opens a
 * modal listing every system with a role picker for that user. Saving goes
 * through the existing per-user endpoint (UserSystemAccessController). The
 * grid is an optional prop, fetched when the modal opens (?user=ID).
 */
class UserAccessController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manageAccess', new Application);
        $this->authorize('viewAny', User::class);

        return Inertia::render('Admin/UserAccess/Index', [
            'hiddenInactive' => User::query()->where('status', 'inactive')->count(),
            'users' => User::query()
                ->where('status', '!=', 'inactive')
                ->with('organizationalUnit:id,name,short_name')
                ->withCount('applicationAccess')
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'email', 'position', 'status', 'organizational_unit_id', 'profile_photo_path'])
                ->map(fn (User $user) => [
                    ...$user->only(['id', 'name', 'username', 'email', 'position']),
                    'status' => $user->status->value,
                    'office' => $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name,
                    'office_name' => $user->organizationalUnit?->name,
                    'profile_photo_url' => $user->profile_photo_url,
                    'systems_count' => $user->application_access_count,
                ]),
            'access' => Inertia::optional(function () use ($request) {
                $user = User::query()->find($request->integer('user'));

                if (! $user) {
                    return null;
                }

                $matrix = new AccessMatrix($request->user());

                return ['user_id' => $user->id, 'locked' => $matrix->lockReason($user), 'systems' => $matrix->forUser($user)];
            }),
        ]);
    }
}
