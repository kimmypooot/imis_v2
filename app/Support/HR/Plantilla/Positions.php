<?php

namespace App\Support\HR\Plantilla;

use App\Enums\PositionLevel;
use App\Models\PlantillaItem;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * HR-4 positions: the only writer of the position list. A plantilla item
 * links to the position of the same title (however it's capitalized or
 * spaced) and follows it: saving a position adjusts the title, salary
 * grade and level of every item linked to it, and a new or edited item
 * takes its position's grade and level (`PlantillaItem::saving`).
 *
 * The grade is the item's only grade, so the Service Record lines on
 * those items show the adjusted grade too, as when HR edits an item.
 */
class Positions
{
    public function __construct(
        private readonly SalarySchedules $schedules,
        private readonly Staffing $staffing,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(?Position $ignore = null): array
    {
        return [
            'title' => ['required', 'string', 'max:255', Rule::unique('positions', 'title')->ignore($ignore)],
            'salary_grade' => ['nullable', 'integer', 'between:1,'.SalarySchedules::GRADES],
            'level' => ['nullable', new Enum(PositionLevel::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Creates or updates a position, then links the items of its title
     * and adjusts every linked item. Returns how many items changed.
     *
     * @param  array<string, mixed>  $data  validated
     * @return array{position: Position, adjusted: int}
     */
    public function save(?Position $position, array $data): array
    {
        return DB::transaction(function () use ($position, $data) {
            $position ??= new Position;
            $position->fill([...$data, 'title' => trim($data['title'])])->save();

            return ['position' => $position, 'adjusted' => $this->adjustItems($position)];
        });
    }

    /**
     * A position can go only while no item uses it.
     */
    public function canDelete(Position $position): bool
    {
        return ! $position->items()->exists();
    }

    /**
     * Adds a position (grade blank) for every title on a staff account or
     * plantilla item that isn't on the list yet. Returns the titles added.
     *
     * @return array<int, string>
     */
    public function addMissingTitles(): array
    {
        $known = Position::query()->pluck('title')->map(fn (string $title) => mb_strtolower(trim($title)));

        $missing = User::query()->whereNotNull('position')->distinct()->pluck('position')
            ->merge(PlantillaItem::query()->distinct()->pluck('position_title'))
            ->map(fn (?string $title) => trim((string) $title))
            ->filter()
            ->unique(fn (string $title) => mb_strtolower($title))
            ->reject(fn (string $title) => $known->contains(mb_strtolower($title)))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        DB::transaction(function () use ($missing) {
            foreach ($missing as $title) {
                $this->save(null, ['title' => $title]);
            }
        });

        return $missing->all();
    }

    /**
     * Fills an item from the position of its title, if there is one.
     * Called while the item is being saved.
     */
    public function applyTo(PlantillaItem $item): void
    {
        $position = filled($item->position_title) ? Position::query()->titled($item->position_title)->first() : null;

        $item->position_id = $position?->id;

        if ($position) {
            $item->position_title = $position->title;
            if ($position->salary_grade !== null) {
                $item->salary_grade = $position->salary_grade;
            }
            if ($position->level !== null) {
                $item->level = $position->level;
            }
        }
    }

    /**
     * Every position with its monthly salary (step 1 of its grade under
     * the schedule in force), its plantilla items — item number, place of
     * assignment and holder — and the offices of the staff carrying the
     * title, for positions with no item yet.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function list(): Collection
    {
        $staff = User::query()->whereNotNull('position')->with('organizationalUnit:id,name,short_name')->get(['id', 'position', 'organizational_unit_id'])
            ->groupBy(fn (User $user) => mb_strtolower(trim($user->position)));

        $positions = Position::query()
            ->with(['items' => fn ($query) => $query->with(['organizationalUnit:id,name,short_name', 'incumbency.employee.user'])->orderBy('item_number')])
            ->get();
        $serving = $this->staffing->servingOf($positions->flatMap->items);

        return $positions
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(function (Position $position) use ($staff, $serving) {
                $holders = $staff[mb_strtolower($position->title)] ?? collect();

                return [
                    'id' => $position->id,
                    'name' => $position->title,
                    'title' => $position->title,
                    'salary_grade' => $position->salary_grade,
                    'monthly_salary' => $position->salary_grade !== null ? $this->schedules->monthlySalary($position->salary_grade, 1) : null,
                    'level' => $position->level?->value,
                    'level_label' => $position->level?->label(),
                    'remarks' => $position->remarks,
                    'items_count' => $position->items->count(),
                    'items' => $position->items->map(fn (PlantillaItem $item) => [
                        'id' => $item->id,
                        'item_number' => $item->item_number,
                        'office' => $item->organizationalUnit?->short_name ?: $item->organizationalUnit?->name,
                        'office_name' => $item->organizationalUnit?->name,
                        'abolished' => $item->isAbolished(),
                        'holder' => $item->incumbency?->employee->user->name,
                        'incomplete' => $item->incumbency !== null && ! $item->incumbency->setRelation('item', $item)->isComplete(),
                        'serving' => $serving[$item->id] ?? null,
                    ])->values(),
                    'staff_count' => $holders->count(),
                    // Where staff with this title are assigned now (their account's office).
                    'staff_offices' => $holders
                        ->map(fn (User $user) => $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name)
                        ->filter()->countBy()->sortKeys()
                        ->map(fn (int $count, string $office) => ['office' => $office, 'count' => $count])
                        ->values(),
                ];
            })
            ->values();
    }

    /**
     * Links the items of the position's title (renamed or not) and
     * brings every linked item into line with it.
     */
    private function adjustItems(Position $position): int
    {
        $items = PlantillaItem::query()
            ->where(fn ($query) => $query
                ->where('position_id', $position->id)
                ->orWhereRaw('LOWER(TRIM(position_title)) = ?', [mb_strtolower($position->title)]))
            ->get();

        $adjusted = 0;
        foreach ($items as $item) {
            // A renamed position keeps its items: give them the new title first.
            if ($item->position_id === $position->id) {
                $item->position_title = $position->title;
            }

            $this->applyTo($item);

            if ($item->isDirty()) {
                $item->save();
                $adjusted++;
            }
        }

        return $adjusted;
    }
}
