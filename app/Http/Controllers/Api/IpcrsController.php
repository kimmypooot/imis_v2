<?php

namespace App\Http\Controllers\Api;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Support\Ipcrs\PmtCommittee;
use Illuminate\Http\JsonResponse;

/**
 * The current employees, for IPCRS: who can be named on a form and have rows
 * cascaded to them, whether or not they have ever opened IPCRS. Active accounts
 * only, with the office each belongs to (their unit, or the nearest ancestor
 * that is a regional unit, field office or satellite office), as in the SSO
 * payload. Called server-to-server (AuthenticateIpcrs).
 */
class IpcrsController extends Controller
{
    public function employees(): JsonResponse
    {
        $units = OrganizationalUnit::query()->get(['id', 'parent_id', 'code', 'name', 'unit_type'])->keyBy('id');

        $officeOf = function (?int $unitId) use ($units): ?OrganizationalUnit {
            for ($unit = $units->get($unitId); $unit !== null; $unit = $units->get($unit->parent_id)) {
                if ($unit->unit_type !== UnitType::Other) {
                    return $unit;
                }
            }

            return null;
        };

        $pmt = PmtCommittee::positions();

        $employees = User::query()
            ->where('status', UserStatus::Active)
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'name', 'username', 'email', 'position', 'organizational_unit_id', 'profile_photo_path'])
            ->map(function (User $user) use ($officeOf, $pmt) {
                $office = $officeOf($user->organizational_unit_id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'position' => $user->position,
                    'office_name' => $office?->name,
                    'office_code' => $office?->code,
                    'photo_url' => $user->profile_photo_url,
                    'in_pmt' => $pmt->has($user->id),
                    'pmt_position' => $pmt->get($user->id) ?: null,
                ];
            });

        return response()->json(['data' => $employees->values()]);
    }
}
