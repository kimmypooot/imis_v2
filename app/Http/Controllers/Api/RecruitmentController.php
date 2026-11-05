<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\PlantillaItem;
use App\Models\PlantillaSelection;
use App\Support\HR\Plantilla\SalarySchedules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the Recruitment Portal reads from and reports to the plantilla.
 * Called server-to-server (AuthenticateRecruitmentPortal).
 */
class RecruitmentController extends Controller
{
    /**
     * The vacant plantilla items the portal can open a vacancy on: active,
     * nobody holds them, and no selection is already waiting on them.
     */
    public function vacantPositions(SalarySchedules $schedules): JsonResponse
    {
        $items = PlantillaItem::query()->active()
            ->whereDoesntHave('incumbency')
            ->whereDoesntHave('selections', fn ($query) => $query->pending())
            ->with('organizationalUnit')
            ->orderBy('position_title')
            ->get();

        return response()->json(['data' => $items->map(fn (PlantillaItem $item) => [
            'id' => $item->id,
            'item_number' => $item->item_number,
            'position_title' => $item->position_title,
            'salary_grade' => $item->salary_grade,
            'monthly_salary' => $item->salary_grade !== null ? $schedules->monthlySalary($item->salary_grade, 1) : null,
            'level' => $item->level?->value,
            'office_code' => $item->organizationalUnit?->code,
            'office_name' => $item->organizationalUnit?->name,
        ])->values()]);
    }

    /**
     * The active staff who hold a role in the Recruitment Portal (its Admin and
     * HRMPSB roles), for the portal's Users page. This is who IMIS lets in with
     * that role; every other employee signs in as an applicant and is not listed.
     * Matches what the sign-in hand-off sends (LegacySso::redeemHub), so the list
     * never disagrees with who can sign in.
     */
    public function users(): JsonResponse
    {
        $application = Application::query()->where('short_name', config('recruitment.application'))->first();

        if (! $application) {
            return response()->json(['data' => []]);
        }

        $users = ApplicationAccess::query()
            ->where('application_id', $application->id)
            ->with(['role:id,name,code', 'user.organizationalUnit:id,code,name'])
            ->get()
            ->filter(fn (ApplicationAccess $access) => $access->user?->status === UserStatus::Active && $access->role?->code)
            ->map(fn (ApplicationAccess $access) => [
                'id' => $access->user->id,
                'fname' => (string) ($access->user->legacyFirstName() ?? $access->user->name),
                'mname' => (string) $access->user->middle_name,
                'lname' => (string) $access->user->last_name,
                'email' => (string) $access->user->email,
                'position' => (string) $access->user->position,
                'role' => $access->role->name,
                'role_code' => $access->role->code,
                'office_name' => $access->user->organizationalUnit?->name,
                'office_code' => $access->user->organizationalUnit?->code,
                'photo_url' => $access->user->profile_photo_url,
            ])
            ->sortBy(fn (array $row) => mb_strtolower($row['lname'].' '.$row['fname']))
            ->values();

        return response()->json(['data' => $users]);
    }

    /**
     * The Appointing Authority picked someone for an item. Recorded as pending
     * for HR; repeating the call for the same vacancy updates it.
     */
    public function selection(Request $request, PlantillaItem $item): JsonResponse
    {
        $data = $request->validate([
            'recruitment_vacancy_id' => ['required', 'integer'],
            'applicant_name' => ['required', 'string', 'max:255'],
            'applicant_email' => ['nullable', 'email', 'max:255'],
            'selected_on' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($item->isAbolished()) {
            return response()->json(['message' => 'This item is abolished.'], 422);
        }

        $selection = $item->selections()->updateOrCreate(
            ['recruitment_vacancy_id' => $data['recruitment_vacancy_id']],
            [...$data, 'status' => PlantillaSelection::PENDING],
        );

        return response()->json(['id' => $selection->id], $selection->wasRecentlyCreated ? 201 : 200);
    }
}
