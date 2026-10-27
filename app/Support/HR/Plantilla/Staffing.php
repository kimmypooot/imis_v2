<?php

namespace App\Support\HR\Plantilla;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Models\PlantillaItem;
use App\Support\HR\EmployeeAssignments;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * HR-4: staffing and personnel movement, for the recruitment and staffing
 * plans. Two places matter and are kept apart:
 *
 * - an item's **place of assignment** — the office it belongs to;
 * - its holder's **station** — where they serve today, from the
 *   reassignment history (HR-1b): the last reassignment, or a running
 *   detail, which wins while it lasts.
 *
 * A holder whose station isn't their item's place of assignment is
 * "serving elsewhere": reassigned out of the item's office, and into the
 * office they serve in.
 *
 * Offices **headed by designation** — someone heading them in a concurrent
 * capacity while staying in their own office, for want of an item there
 * (e.g. one Field Director over two field offices) — are listed apart.
 * The designee isn't counted as serving there: they still hold, and serve
 * in, their own item.
 */
class Staffing
{
    public function __construct(
        private readonly EmployeeAssignments $assignments,
        private readonly SalarySchedules $schedules,
    ) {}

    /**
     * Where the holder of each filled item serves today, keyed by item id.
     *
     * @param  Collection<int, PlantillaItem>  $items  with incumbency.employee.user
     * @return Collection<int, array<string, mixed>>
     */
    public function servingOf(Collection $items): Collection
    {
        $held = $items->filter(fn (PlantillaItem $item) => $item->incumbency !== null);
        $employees = EloquentCollection::make($held->map(fn (PlantillaItem $item) => $item->incumbency->employee)->unique('id')->values());

        if ($employees->isEmpty()) {
            return collect();
        }

        $employees->load('user.organizationalUnit');
        $stations = $this->assignments->stationsOn($employees, today())->keyBy('employee_id');

        return $held->mapWithKeys(function (PlantillaItem $item) use ($stations) {
            $station = $stations[$item->incumbency->employee_id];
            $atId = $station['detailed_to_id'] ?? $station['office_id'];

            return [$item->id => [
                'office' => $station['detailed_to'] ?? $station['office'],
                'office_id' => $atId,
                'elsewhere' => $item->organizational_unit_id !== null && $atId !== null && $atId !== $item->organizational_unit_id,
                'detail' => $station['detailed_to'] !== null,
                'kind' => $station['move_kind'],
                'since' => $station['moved_on'],
                'order_number' => $station['order_number'],
            ]];
        });
    }

    /**
     * The staffing picture: per office, its items and who is actually
     * there; the holders serving away from their place of assignment; and
     * the vacant items (the recruitment list).
     *
     * @return array{offices: Collection<int, array<string, mixed>>, elsewhere: Collection<int, array<string, mixed>>, vacancies: Collection<int, array<string, mixed>>, designations: Collection<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function overview(): array
    {
        $items = PlantillaItem::query()->active()
            ->with(['organizationalUnit', 'incumbency.employee.user', 'selections' => fn ($query) => $query->pending()])
            ->get();
        $serving = $this->servingOf($items);

        // In-service staff with an active account but no item, by where they serve now.
        $unlinked = Employee::query()
            ->whereDoesntHave('plantillaAppointments', fn ($query) => $query->whereNull('date_to'))
            ->with('user.organizationalUnit')->get()
            ->reject(fn (Employee $employee) => $employee->isSeparated() || ! $employee->user->isActive());
        $unlinkedAt = $unlinked->isEmpty()
            ? collect()
            : $this->assignments->stationsOn($unlinked, today())->countBy(fn (array $station) => $station['detailed_to_id'] ?? $station['office_id']);

        $designations = $this->headDesignations($items);

        $offices = OrganizationalUnit::query()->orderBy('name')->get()
            ->map(function (OrganizationalUnit $unit) use ($items, $serving, $unlinkedAt, $designations) {
                $own = $items->where('organizational_unit_id', $unit->id);
                $filled = $own->filter(fn (PlantillaItem $item) => $item->incumbency !== null);
                $out = $filled->filter(fn (PlantillaItem $item) => $serving[$item->id]['elsewhere']);
                $in = $items->filter(fn (PlantillaItem $item) => isset($serving[$item->id])
                    && $serving[$item->id]['elsewhere'] && $serving[$item->id]['office_id'] === $unit->id);

                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'short_name' => $unit->short_name,
                    'items' => $own->count(),
                    'filled' => $filled->count(),
                    'vacant' => $own->count() - $filled->count(),
                    'reassigned_out' => $out->count(),
                    'reassigned_in' => $in->count(),
                    // Plantilla personnel actually serving here.
                    'present' => $filled->count() - $out->count() + $in->count(),
                    'without_item' => $unlinkedAt[$unit->id] ?? 0,
                    // Headed by someone from another office, in a concurrent capacity.
                    'headed_by' => $designations->where('office_id', $unit->id)->map(fn (array $row) => "{$row['name']} ({$row['capacity']})")->values(),
                    // This office's people who also head another office.
                    'also_heads' => $designations->where('home_office_id', $unit->id)->pluck('office')->values(),
                ];
            })
            // Offices with nothing to show stay off the table.
            ->filter(fn (array $row) => $row['items'] + $row['reassigned_in'] + $row['without_item'] + $row['headed_by']->count() > 0)
            ->values();

        $elsewhere = $items
            ->filter(fn (PlantillaItem $item) => isset($serving[$item->id]) && $serving[$item->id]['elsewhere'])
            ->map(fn (PlantillaItem $item) => [
                'item_id' => $item->id,
                'item' => $item->reference,
                'position_title' => $item->position_title,
                'place_of_assignment' => $this->unitName($item->organizationalUnit),
                'employee_id' => $item->incumbency->employee_id,
                'name' => $item->incumbency->employee->user->name,
                ...collect($serving[$item->id])->only(['office', 'detail', 'kind', 'since', 'order_number'])->all(),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $vacancies = $items
            ->filter(fn (PlantillaItem $item) => $item->incumbency === null)
            ->map(fn (PlantillaItem $item) => [
                'item_id' => $item->id,
                'item_number' => $item->item_number,
                'position_title' => $item->position_title,
                'salary_grade' => $item->salary_grade,
                'monthly_salary' => $item->salary_grade !== null ? $this->schedules->monthlySalary($item->salary_grade, 1) : null,
                'place_of_assignment' => $this->unitName($item->organizationalUnit),
                // Whom the Recruitment Portal's Appointing Authority picked; HR still appoints them here.
                'selected' => $item->selections->first()?->applicant_name,
            ])
            ->sortBy([['place_of_assignment', 'asc'], ['position_title', 'asc']])
            ->values();

        return [
            'offices' => $offices,
            'elsewhere' => $elsewhere,
            'vacancies' => $vacancies,
            'designations' => $designations,
            'totals' => [
                'items' => $items->count(),
                'filled' => $items->filter(fn (PlantillaItem $item) => $item->incumbency !== null)->count(),
                'vacant' => $vacancies->count(),
                'elsewhere' => $elsewhere->count(),
                'without_item' => $unlinked->count(),
                'no_place' => $items->whereNull('organizational_unit_id')->count(),
                'headed_by_designation' => $designations->pluck('office_id')->unique()->count(),
            ],
        ];
    }

    /**
     * Running head-of-office designations: who heads which office in a
     * concurrent capacity, from which office, and on what item.
     *
     * @param  Collection<int, PlantillaItem>  $items  with incumbency
     * @return Collection<int, array<string, mixed>>
     */
    private function headDesignations(Collection $items): Collection
    {
        $itemOf = $items->filter(fn (PlantillaItem $item) => $item->incumbency !== null)->keyBy(fn (PlantillaItem $item) => $item->incumbency->employee_id);

        return EmployeeAssignment::query()->headingOn()
            ->with(['employee.user.organizationalUnit', 'toUnit'])
            ->orderBy('effective_date')
            ->get()
            ->map(function (EmployeeAssignment $designation) use ($itemOf) {
                $employee = $designation->employee;
                $item = $itemOf[$employee->id] ?? null;

                return [
                    'id' => $designation->id,
                    'office_id' => $designation->to_unit_id,
                    'office' => $this->unitName($designation->toUnit),
                    'employee_id' => $employee->id,
                    'name' => $employee->user->name,
                    'capacity' => $designation->capacity()->shortLabel(),
                    'home_office_id' => $employee->user->organizational_unit_id,
                    'home_office' => $this->unitName($employee->user->organizationalUnit),
                    'position_title' => $item?->position_title ?? $employee->user->position,
                    'item_id' => $item?->id,
                    'item' => $item?->reference,
                    'since' => $designation->effective_date->toDateString(),
                    'until' => $designation->end_date?->toDateString(),
                    'order_number' => $designation->order_number,
                ];
            })
            ->sortBy('office', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function unitName(?OrganizationalUnit $unit): ?string
    {
        return $unit ? ($unit->short_name ?: $unit->name) : null;
    }
}
