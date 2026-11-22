<?php

namespace App\Http\Controllers\Api;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * The employees TIMS may add as staff, for its "Add Staff Account" form:
 * active accounts whose office (their unit, or the nearest ancestor that is a
 * regional unit, field office or satellite office) is a field or satellite
 * office, or one of the regional units in `tims.regional_units` — the ORD and
 * the Human Resource Division. Nobody else works in TIMS.
 *
 * Each row carries the same name and office fields the sign-in hand-off sends
 * (LegacySso::redeemHub), so the account TIMS creates from it is the account
 * that person's first sign-in would make, plus the TIMS role IMIS already
 * grants them, if any. Called server-to-server (AuthenticateTims).
 */
class TimsController extends Controller
{
    /** @var Collection<int, OrganizationalUnit>|null */
    private ?Collection $units = null;

    public function employees(): JsonResponse
    {
        $application = Application::query()->where('short_name', config('tims.application'))->first();

        $grants = ApplicationAccess::query()
            ->where('application_id', $application?->id ?? 0)
            ->with('role:id,code')
            ->get()
            ->mapWithKeys(fn (ApplicationAccess $access) => [$access->user_id => $access->role?->code]);

        $regional = (array) config('tims.regional_units');

        $employees = User::query()
            ->where('status', UserStatus::Active)
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(fn (User $user) => [$user, $this->officeOf($user->organizational_unit_id)])
            ->filter(fn (array $pair) => $pair[1] !== null && (
                in_array($pair[1]->unit_type, [UnitType::FieldOffice, UnitType::SatelliteOffice], true)
                || ($pair[1]->unit_type === UnitType::RegionalSupportUnit && in_array($pair[1]->code, $regional, true))
            ))
            ->map(fn (array $pair) => [
                'id' => $pair[0]->id,
                'fname' => (string) ($pair[0]->legacyFirstName() ?? $pair[0]->name),
                'mname' => (string) $pair[0]->middle_name,
                'lname' => (string) $pair[0]->last_name,
                'email' => (string) $pair[0]->email,
                'position' => (string) $pair[0]->position,
                'office_name' => $pair[1]->name,
                'office_code' => $pair[1]->code,
                'office_type' => $pair[1]->unit_type->value,
                'role_code' => $grants->get($pair[0]->id),
            ]);

        return response()->json(['data' => $employees->values()]);
    }

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
