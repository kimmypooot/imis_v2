<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\UpdateSystemAccessRequest;
use App\Models\User;
use App\Support\SystemAccess\SystemAccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * The per-user side of system access: one person's role in every system,
 * saved together from the user's admin page. Same rules and audit trail
 * as the per-system screen — both go through SystemAccessManager.
 */
class UserSystemAccessController extends Controller
{
    public function update(UpdateSystemAccessRequest $request, User $user, SystemAccessManager $access): RedirectResponse
    {
        DB::transaction(function () use ($request, $user, $access) {
            foreach ($request->changes() as $change) {
                $access->set($change['application'], $user, $change['role']);
            }
        });

        return back()->with('status', "System access for {$user->name} was saved.");
    }
}
