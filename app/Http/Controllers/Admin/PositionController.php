<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\PositionLevel;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\PlantillaItem;
use App\Models\Position;
use App\Support\HR\Plantilla\Positions;
use App\Support\HR\Plantilla\SalarySchedules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-4: the position list (Human Resources → Positions) — each title's
 * salary grade and level. Saving a position adjusts the plantilla items
 * of that title. Same permissions as the plantilla.
 */
class PositionController extends Controller
{
    public function index(Request $request, Positions $positions, SalarySchedules $schedules): Response
    {
        $this->authorize('viewAny', PlantillaItem::class);

        $canManage = $request->user()->can('manage', PlantillaItem::class);
        $schedule = $schedules->inForceOn();

        return Inertia::render('Admin/HR/Positions/Index', [
            'positions' => $positions->list(),
            'schedule' => $schedule ? ['name' => $schedule->name, 'effective_date' => $schedule->effective_date->toDateString()] : null,
            'canManage' => $canManage,
            'form' => $canManage ? [
                'levelOptions' => PositionLevel::options(),
                'gradeOptions' => array_map(fn (int $grade) => ['value' => $grade, 'label' => "SG {$grade}"], range(1, SalarySchedules::GRADES)),
                // For "Add item" on a position (saved through the plantilla).
                'officeOptions' => OrganizationalUnit::query()->active()->orderBy('name')->get()
                    ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]),
            ] : null,
        ]);
    }

    public function store(Request $request, Positions $positions): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        ['position' => $position, 'adjusted' => $adjusted] = $positions->save(null, $request->validate($positions->rules(), [], $this->attributes()));

        $this->audit()->record(AuditEvent::PositionCreated, "Created {$position->label}", $position, ['items_adjusted' => $adjusted]);

        return back()->with('status', "{$position->title} was added".$this->adjustedNote($adjusted).'.');
    }

    public function update(Request $request, Position $position, Positions $positions): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $before = $position->only(['title', 'salary_grade', 'level', 'remarks']);
        ['adjusted' => $adjusted] = $positions->save($position, $request->validate($positions->rules($position), [], $this->attributes()));

        $this->audit()->record(AuditEvent::PositionUpdated, "Updated {$position->label}", $position, [
            'before' => $before,
            'after' => $position->only(['title', 'salary_grade', 'level', 'remarks']),
            'items_adjusted' => $adjusted,
        ]);

        return back()->with('status', "{$position->title} was saved".$this->adjustedNote($adjusted).'.');
    }

    public function destroy(Position $position, Positions $positions): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        if (! $positions->canDelete($position)) {
            return back()->with('error', "{$position->title} is used by plantilla items, so it can't be deleted.");
        }

        $position->delete();
        $this->audit()->record(AuditEvent::PositionDeleted, "Deleted {$position->label}", $position);

        return back()->with('status', "{$position->title} was deleted.");
    }

    /**
     * Adds every title found on staff accounts and plantilla items that
     * isn't on the list yet, with its grade blank for HR to fill in.
     */
    public function addFromStaff(Positions $positions): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $added = $positions->addMissingTitles();

        if ($added) {
            $this->audit()->record(AuditEvent::PositionsAddedFromStaff, 'Added '.count($added).' positions from staff titles', null, ['titles' => $added]);
        }

        return back()->with('status', $added
            ? 'Added '.count($added).' '.str('position')->plural(count($added)).'. Enter their salary grades.'
            : 'Every title on staff accounts is already on the list.');
    }

    private function adjustedNote(int $adjusted): string
    {
        return $adjusted ? "; {$adjusted} plantilla ".str('item')->plural($adjusted).' adjusted' : '';
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['title' => 'position title', 'salary_grade' => 'salary grade'];
    }
}
