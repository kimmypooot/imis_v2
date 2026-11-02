<?php

namespace App\Http\Controllers\Api;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\OrganizationalUnit;
use Illuminate\Http\JsonResponse;

/**
 * The people who hold a PROCTAD role, for PROCTAD's Users page: active
 * accounts with access to the PROCTAD entry, the role that access gives
 * (`role_code`, a PROCTAD UserRole value) and the office they belong to (their
 * unit, or the nearest ancestor that is a regional unit, field office or
 * satellite office) — the same facts the sign-in hand-off sends
 * (LegacySso::redeemHub), so this list never disagrees with who can sign in.
 * Called server-to-server (AuthenticateProctad).
 */
class ProctadController extends Controller
{
    public function users(): JsonResponse
    {
        $application = Application::query()->where('short_name', config('proctad.application'))->first();

        if (! $application) {
            return response()->json(['data' => []]);
        }

        $units = OrganizationalUnit::query()->get(['id', 'parent_id', 'code', 'name', 'unit_type'])->keyBy('id');

        $officeOf = function (?int $unitId) use ($units): ?OrganizationalUnit {
            for ($unit = $units->get($unitId); $unit !== null; $unit = $units->get($unit->parent_id)) {
                if ($unit->unit_type !== UnitType::Other) {
                    return $unit;
                }
            }

            return null;
        };

        $users = ApplicationAccess::query()
            ->where('application_id', $application->id)
            ->with(['role:id,name,code', 'user'])
            ->get()
            ->filter(fn (ApplicationAccess $access) => $access->user?->status === UserStatus::Active && $access->role)
            ->map(function (ApplicationAccess $access) use ($officeOf) {
                $user = $access->user;
                $office = $officeOf($user->organizational_unit_id);

                return [
                    'id' => $user->id,
                    'username' => $user->username,
                    'fname' => (string) ($user->legacyFirstName() ?? $user->name),
                    'mname' => (string) $user->middle_name,
                    'lname' => (string) $user->last_name,
                    'email' => (string) $user->email,
                    'position' => (string) $user->position,
                    'given_name' => (string) $user->first_name,
                    'suffix' => (string) $user->name_extension,
                    'sex' => $user->sex,
                    'photo_url' => $user->profile_photo_url,
                    'role' => $access->role->name,
                    'role_code' => $access->role->code,
                    'office_name' => $office?->name,
                    'office_code' => $office?->code,
                    'office_type' => $office?->unit_type->value,
                    'active' => true,
                ];
            })
            ->sortBy(fn (array $row) => mb_strtolower($row['lname'].' '.$row['fname']))
            ->values();

        return response()->json(['data' => $users]);
    }
}
