<?php

namespace App\Http\Controllers\Api;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        $users = $this->accesses()
            ->get()
            ->filter(fn (ApplicationAccess $access) => $access->user?->status === UserStatus::Active && $access->role)
            ->map(fn (ApplicationAccess $access) => $this->row($access))
            ->sortBy(fn (array $row) => mb_strtolower($row['lname'].' '.$row['fname']))
            ->values();

        return response()->json(['data' => $users]);
    }

    /**
     * One person, with the personal details PROCTAD keeps on a test
     * administrator's record — birthday and mobile number — so registering
     * them there, or their next sign-in, fills those from IMIS instead of
     * asking again. Kept off the list above, which PROCTAD caches: these are
     * fetched for one person, when needed, and not stored in bulk.
     *
     * Only someone holding a PROCTAD role can be looked up.
     */
    public function user(int $user): JsonResponse
    {
        $access = $this->accesses()
            ->with('user.employee.pdsProfile')
            ->where('user_id', $user)
            ->first();

        abort_unless($access && $access->user?->status === UserStatus::Active && $access->role, 404);

        $pds = $access->user->employee?->pdsProfile;

        return response()->json(['data' => $this->row($access) + [
            'birthday' => $access->user->birthday?->toDateString(),
            'mobile' => $pds?->mobile_no ?: null,
        ]]);
    }

    /** Everyone with access to the PROCTAD entry; empty when IMIS has none. */
    private function accesses(): Builder
    {
        $application = Application::query()->where('short_name', config('proctad.application'))->first();

        return ApplicationAccess::query()
            ->where('application_id', $application?->id ?? 0)
            ->with(['role:id,name,code', 'user']);
    }

    /** @return array<string, mixed> */
    private function row(ApplicationAccess $access): array
    {
        $user = $access->user;
        $office = $this->officeOf($user->organizational_unit_id);

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
    }

    /** @var Collection<int, OrganizationalUnit>|null */
    private ?Collection $units = null;

    /**
     * The person's office: their unit, or the nearest ancestor that is a
     * regional unit, field office or satellite office.
     */
    private function officeOf(?int $unitId): ?OrganizationalUnit
    {
        $this->units ??= OrganizationalUnit::query()->get(['id', 'parent_id', 'code', 'name', 'unit_type'])->keyBy('id');

        for ($unit = $this->units->get($unitId); $unit !== null; $unit = $this->units->get($unit->parent_id)) {
            if ($unit->unit_type !== UnitType::Other) {
                return $unit;
            }
        }

        return null;
    }
}
