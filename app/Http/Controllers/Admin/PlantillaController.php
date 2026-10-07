<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentNature;
use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Enums\PositionLevel;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Support\HR\Plantilla\Plantilla;
use App\Support\HR\Plantilla\SalarySchedules;
use App\Support\HR\Plantilla\Staffing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-4: the plantilla of positions (Human Resources → Plantilla) — every
 * item with its holder and monthly salary, filled / vacant / abolished
 * views, and one item's history of holders.
 */
class PlantillaController extends Controller
{
    public function index(Request $request, Plantilla $plantilla, SalarySchedules $schedules, Staffing $staffing): Response
    {
        $this->authorize('viewAny', PlantillaItem::class);

        $models = PlantillaItem::query()
            ->with(['organizationalUnit', 'incumbency.employee.user'])
            ->orderBy('item_number')
            ->get();
        $serving = $staffing->servingOf($models);
        $items = $models->map(fn (PlantillaItem $item) => [...$plantilla->presentItem($item), 'serving' => $serving[$item->id] ?? null]);

        $schedule = $schedules->inForceOn();
        $canManage = $request->user()->can('manage', PlantillaItem::class);

        return Inertia::render('Admin/HR/Plantilla/Index', [
            'items' => $items,
            'counts' => [
                'total' => $items->where('status', '!==', 'abolished')->count(),
                'filled' => $items->where('status', 'filled')->count(),
                'vacant' => $items->where('status', 'vacant')->count(),
                'abolished' => $items->where('status', 'abolished')->count(),
            ],
            'schedule' => $schedule ? ['name' => $schedule->name, 'reference' => $schedule->reference, 'effective_date' => $schedule->effective_date->toDateString()] : null,
            'canManage' => $canManage,
            'itemForm' => $canManage ? $this->itemForm($plantilla) : null,
        ]);
    }

    public function show(Request $request, PlantillaItem $item, Plantilla $plantilla, Staffing $staffing): Response
    {
        $this->authorize('view', $item);

        $item->load(['organizationalUnit', 'incumbency.employee.user']);
        $canManage = $request->user()->can('manage', PlantillaItem::class);

        return Inertia::render('Admin/HR/Plantilla/Show', [
            'item' => [...$plantilla->presentItem($item), 'serving' => $staffing->servingOf(collect([$item]))[$item->id] ?? null],
            'history' => $item->appointments()->with(['item', 'employee.user'])
                ->orderByDesc('date_from')->orderByDesc('id')->get()
                ->map(fn (PlantillaAppointment $appointment) => [
                    ...$plantilla->presentAppointment($appointment),
                    'can_manage' => $request->user()->can('appoint', [PlantillaItem::class, $appointment->employee]),
                    'can_correct' => $request->user()->can('correct', [PlantillaItem::class, $appointment->employee]),
                ]),
            'canManage' => $canManage,
            'itemForm' => $canManage ? $this->itemForm($plantilla) : null,
            'appointForm' => $canManage && ! $item->isAbolished() && ! $item->incumbency
                ? $plantilla->appointableEmployees($item, $request->user())
                : null,
            'appointmentOptions' => [
                'statusOptions' => EmploymentStatus::plantillaOptions(),
                'natureOptions' => AppointmentNature::appointmentOptions(),
                'allNatureOptions' => array_map(fn (AppointmentNature $nature) => ['value' => $nature->value, 'label' => $nature->label()], AppointmentNature::cases()),
            ],
        ]);
    }

    public function store(Request $request, Plantilla $plantilla): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $item = $plantilla->createItem($request->validate($plantilla->itemRules(), [], $this->attributes()));

        $this->audit()->record(AuditEvent::PlantillaItemCreated, "Created {$item->label}", $item);

        $message = "Item {$item->reference} was added.";

        // Added from the Positions page: stay there.
        return $request->boolean('stay')
            ? back()->with('status', $message)
            : to_route('admin.hr.plantilla.show', $item)->with('status', $message);
    }

    public function update(Request $request, PlantillaItem $item, Plantilla $plantilla): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $plantilla->updateItem($item, $request->validate($plantilla->itemRules($item), [], $this->attributes()));

        $this->audit()->recordChanges(AuditEvent::PlantillaItemUpdated, "Updated {$item->label}", $item);

        return back()->with('status', "Item {$item->reference} was saved.");
    }

    public function abolish(Request $request, PlantillaItem $item, Plantilla $plantilla): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $data = $request->validate([
            'abolished_on' => ['required', 'date'],
            'abolition_authority' => ['nullable', 'string', 'max:255'],
        ], [], ['abolished_on' => 'date abolished', 'abolition_authority' => 'authority']);

        $plantilla->abolish($item, $data['abolished_on'], $data['abolition_authority'] ?? null);

        $this->audit()->record(AuditEvent::PlantillaItemAbolished, "Abolished {$item->label}", $item, array_filter($data));

        return back()->with('status', "Item {$item->reference} was abolished.");
    }

    public function restore(PlantillaItem $item, Plantilla $plantilla): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $plantilla->restore($item);

        $this->audit()->record(AuditEvent::PlantillaItemRestored, "Restored {$item->label}", $item);

        return back()->with('status', "Item {$item->reference} was restored.");
    }

    /**
     * @return array<string, mixed>
     */
    private function itemForm(Plantilla $plantilla): array
    {
        return [
            'levelOptions' => PositionLevel::options(),
            'officeOptions' => OrganizationalUnit::query()->active()->orderBy('name')->get()
                ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]),
            'gradeOptions' => array_map(fn (int $grade) => ['value' => $grade, 'label' => "SG {$grade}"], range(1, SalarySchedules::GRADES)),
            'positionTitles' => $plantilla->positionTitles(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['item_number' => 'item number', 'position_title' => 'position title', 'salary_grade' => 'salary grade', 'organizational_unit_id' => 'office'];
    }
}
