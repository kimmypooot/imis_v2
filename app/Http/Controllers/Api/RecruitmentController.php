<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
