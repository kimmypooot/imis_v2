<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\PlantillaItem;
use App\Models\SalarySchedule;
use App\Support\HR\Plantilla\SalarySchedules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-4: salary schedules (grade × step), entered by HR from the issuance
 * (e.g. the 3rd tranche, 2026, NBC No. 601). The one in force prices the
 * plantilla. `?schedule=` picks the table shown.
 */
class SalaryScheduleController extends Controller
{
    public function index(Request $request, SalarySchedules $schedules): Response
    {
        $this->authorize('viewAny', PlantillaItem::class);

        $all = SalarySchedule::query()->withCount('rates')->orderByDesc('effective_date')->get();
        $inForce = $schedules->inForceOn();
        $selected = $all->firstWhere('id', $request->integer('schedule')) ?? $inForce ?? $all->first();

        return Inertia::render('Admin/HR/SalarySchedules/Index', [
            'schedules' => $all->map(fn (SalarySchedule $schedule) => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'reference' => $schedule->reference,
                'effective_date' => $schedule->effective_date->toDateString(),
                'remarks' => $schedule->remarks,
                'cells' => $schedule->rates_count,
                'in_force' => $inForce?->is($schedule) ?? false,
            ]),
            'selectedId' => $selected?->id,
            'grid' => $selected ? $schedules->grid($selected) : null,
            'grades' => SalarySchedules::GRADES,
            'steps' => SalarySchedules::STEPS,
            'canManage' => $request->user()->can('manage', PlantillaItem::class),
        ]);
    }

    public function store(Request $request, SalarySchedules $schedules): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        ['schedule' => $schedule, 'cells' => $cells] = $schedules->save(null, $request->validate($schedules->rules(), [], $this->attributes()));

        $this->audit()->record(AuditEvent::SalaryScheduleSaved, "Created {$schedule->label}", $schedule, ['cells' => $cells]);

        return to_route('admin.hr.salary-schedules.index', ['schedule' => $schedule->id])->with('status', "{$schedule->name} was added with {$cells} amounts.");
    }

    public function update(Request $request, SalarySchedule $schedule, SalarySchedules $schedules): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        ['cells' => $cells] = $schedules->save($schedule, $request->validate($schedules->rules($schedule), [], $this->attributes()));

        $this->audit()->record(AuditEvent::SalaryScheduleSaved, "Updated {$schedule->label}", $schedule, ['cells' => $cells]);

        return back()->with('status', "{$schedule->name} was saved ({$cells} amounts).");
    }

    public function destroy(SalarySchedule $schedule): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $schedule->delete();

        $this->audit()->record(AuditEvent::SalaryScheduleDeleted, "Deleted {$schedule->label}", null, ['effective_date' => $schedule->effective_date->toDateString()]);

        return to_route('admin.hr.salary-schedules.index')->with('status', "{$schedule->name} was deleted.");
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['effective_date' => 'effective date', 'rates.*.*' => 'amount'];
    }
}
