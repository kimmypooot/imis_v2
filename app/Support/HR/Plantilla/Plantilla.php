<?php

namespace App\Support\HR\Plantilla;

use App\Enums\AppointmentNature;
use App\Enums\EmploymentStatus;
use App\Enums\PositionLevel;
use App\Models\Employee;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * HR-4: the only writer of plantilla items and of who holds them.
 *
 * An appointment is one period of service on an item at a step and
 * status (a Service Record line). Appointing someone who already holds
 * another item (a promotion, a transfer) ends that one the day before.
 * A step increment ends the current period and opens the next on the
 * same item. The holder's employment status (HR-1) and their account's
 * position title follow the appointment; their office does not — that
 * is the reassignment history's job (HR-1b).
 */
class Plantilla
{
    public function __construct(private readonly SalarySchedules $schedules) {}

    // ── Items ────────────────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    public function itemRules(?PlantillaItem $ignore = null): array
    {
        return [
            // Both may wait until HR has them; the item then shows as incomplete.
            'item_number' => ['nullable', 'string', 'max:100', Rule::unique('plantilla_items', 'item_number')->ignore($ignore)],
            'position_title' => ['required', 'string', 'max:255'],
            'salary_grade' => ['nullable', 'integer', 'between:1,'.SalarySchedules::GRADES],
            'level' => ['nullable', new Enum(PositionLevel::class)],
            'organizational_unit_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
            'authority' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Position titles to suggest for an item: those already on staff
     * accounts and on other items, so an appointment (which copies the
     * item's title onto the holder's account) keeps the same spelling.
     *
     * @return array<int, string>
     */
    public function positionTitles(): array
    {
        return User::query()->whereNotNull('position')->distinct()->pluck('position')
            ->merge(PlantillaItem::query()->distinct()->pluck('position_title'))
            ->map(fn (string $title) => trim($title))
            ->filter()
            ->unique(fn (string $title) => mb_strtolower($title))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function createItem(array $data): PlantillaItem
    {
        return PlantillaItem::query()->create($data);
    }

    /**
     * Retitling an item also retitles its current holder's account.
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function updateItem(PlantillaItem $item, array $data): void
    {
        DB::transaction(function () use ($item, $data) {
            $item->fill($data)->save();

            if ($item->wasChanged('position_title') && ($holder = $item->incumbency()->with('employee.user')->first())) {
                $holder->employee->user->forceFill(['position' => $item->position_title])->save();
            }
        });
    }

    /**
     * Abolishing keeps the item for the record. Only a vacant item can be.
     */
    public function abolish(PlantillaItem $item, string $date, ?string $authority): void
    {
        if ($item->incumbency()->exists()) {
            throw ValidationException::withMessages(['abolished_on' => 'Vacate the item before abolishing it.']);
        }

        $item->forceFill(['abolished_at' => now(), 'abolished_on' => $date, 'abolition_authority' => $authority])->save();
    }

    public function restore(PlantillaItem $item): void
    {
        $item->forceFill(['abolished_at' => null, 'abolished_on' => null, 'abolition_authority' => null])->save();
    }

    // ── Appointments ─────────────────────────────────────────────────

    /**
     * Whom HR can appoint to an item: anyone in service except the viewer
     * (never your own appointment). Staff whose account position is the
     * item's title come first as matches; the rest show their title.
     *
     * @return array{employees: array<int, array<string, mixed>>, matches: int}
     */
    public function appointableEmployees(PlantillaItem $item, User $viewer): array
    {
        $title = mb_strtolower(trim($item->position_title));

        [$matches, $others] = Employee::query()->with('user')->get()
            ->reject(fn (Employee $employee) => $employee->isSeparated() || $employee->user_id === $viewer->id)
            ->sortBy(fn (Employee $employee) => $employee->user->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->partition(fn (Employee $employee) => mb_strtolower(trim((string) $employee->user->position)) === $title);

        $option = fn (Employee $employee, bool $showTitle) => [
            'value' => $employee->id,
            'label' => $showTitle && filled($employee->user->position) ? "{$employee->user->name} — {$employee->user->position}" : $employee->user->name,
        ];

        $employees = $matches->isEmpty()
            ? $others->map(fn (Employee $employee) => $option($employee, true))->values()->all()
            : [
                ['label' => "Position matches ({$item->position_title})", 'options' => $matches->map(fn (Employee $employee) => $option($employee, false))->values()->all()],
                ['label' => 'Other staff', 'options' => $others->map(fn (Employee $employee) => $option($employee, true))->values()->all()],
            ];

        return ['employees' => $employees, 'matches' => $matches->count()];
    }

    /**
     * Links someone who holds no item yet to a new item of their account's
     * position, in their account's office, before HR has the details
     * (`imis:link-plantilla`). Only what's on record is filled in: the
     * item number, step, nature and start date stay blank, the status is
     * their employee record's when it's a plantilla status, and the grade
     * comes from the Positions list. The link is incomplete until HR
     * completes it (Correct), and stays off the Service Record till then.
     */
    public function linkFromAccount(Employee $employee): PlantillaAppointment
    {
        $employee->loadMissing('user');

        if ($employee->plantillaAppointments()->whereNull('date_to')->exists()) {
            throw new \LogicException("{$employee->user->name} already holds a plantilla item.");
        }

        return DB::transaction(function () use ($employee) {
            $item = PlantillaItem::query()->create([
                'position_title' => trim((string) $employee->user->position),
                'organizational_unit_id' => $employee->user->organizational_unit_id,
                'remarks' => 'Linked from the staff account on '.today()->toDateString()
                    .'. The place of assignment is the office the account showed then; if they were already serving elsewhere on a reassignment order, set it to the item\'s own office.',
            ]);

            $status = in_array($employee->employment_status, EmploymentStatus::onPlantilla(), true) ? $employee->employment_status : null;

            $appointment = new PlantillaAppointment(['status' => $status]);
            $appointment->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id])->save();

            return $appointment;
        });
    }

    /**
     * Links someone to a vacant item picked on the user form, before HR has
     * the details. Their current period (if any) ends today; the new one
     * has only the item, so it stays incomplete (and off the Service
     * Record) until HR completes it. The account position follows the item.
     */
    public function linkToItem(Employee $employee, PlantillaItem $item, User $actor): ?PlantillaAppointment
    {
        $current = $employee->plantillaAppointments()->whereNull('date_to')->first();

        if ($current?->plantilla_item_id === $item->id) {
            return null;
        }
        if ($item->isAbolished() || $item->incumbency()->exists()) {
            throw ValidationException::withMessages(['plantilla_item_id' => 'That item is not vacant.']);
        }

        return DB::transaction(function () use ($employee, $item, $actor, $current) {
            $current?->forceFill([
                'date_to' => $current->date_from && $current->date_from->gt(today()) ? $current->date_from : today(),
                'end_reason' => "Reassigned to {$item->reference}",
            ])->save();

            $status = in_array($employee->employment_status, EmploymentStatus::onPlantilla(), true) ? $employee->employment_status : null;

            $appointment = new PlantillaAppointment(['status' => $status]);
            $appointment->forceFill([
                'plantilla_item_id' => $item->id,
                'employee_id' => $employee->id,
                'recorded_by' => $actor->id,
            ])->save();

            $this->followHolder($appointment);

            return $appointment;
        });
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function appointmentRules(bool $choosingEmployee = true): array
    {
        return [
            ...($choosingEmployee ? ['employee_id' => ['required', 'integer', 'exists:employees,id']] : []),
            'step' => ['required', 'integer', 'between:1,'.SalarySchedules::STEPS],
            'status' => ['required', Rule::enum(EmploymentStatus::class)->only(EmploymentStatus::onPlantilla())],
            'nature' => ['required', Rule::enum(AppointmentNature::class)],
            'date_from' => ['required', 'date', 'after:1900-01-01'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Puts someone on a vacant item. If they hold another item now, that
     * period ends the day before.
     *
     * @param  array<string, mixed>  $data  validated
     * @return array{appointment: PlantillaAppointment, ended: ?PlantillaAppointment}
     */
    public function appoint(PlantillaItem $item, Employee $employee, array $data, User $actor): array
    {
        $from = Carbon::parse($data['date_from'])->startOfDay();

        if ($item->isAbolished()) {
            throw ValidationException::withMessages(['employee_id' => 'This item is abolished.']);
        }
        if ($item->incumbency()->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'This item is already filled. Vacate it first.']);
        }
        if ($item->appointments()->whereDate('date_to', '>=', $from)->exists()) {
            throw ValidationException::withMessages(['date_from' => 'The item was still held by someone on that date.']);
        }

        $current = $employee->plantillaAppointments()->whereNull('date_to')->with('item')->first();
        if ($current && ! $current->isComplete()) {
            throw ValidationException::withMessages(['employee_id' => "Their current link to {$current->item->reference} is incomplete (missing ".implode(', ', $current->missingDetails()).'). Complete it first, so the earlier period can end on the right day.']);
        }
        if ($current && $current->date_from->gte($from)) {
            throw ValidationException::withMessages(['date_from' => "They hold {$current->item->reference} since ".$current->date_from->toDateString().'; the new appointment must start after that.']);
        }

        return DB::transaction(function () use ($item, $employee, $data, $actor, $from, $current) {
            $nature = AppointmentNature::from($data['nature']);

            if ($current) {
                $current->forceFill([
                    'date_to' => $from->copy()->subDay(),
                    'end_reason' => "{$nature->label()} to {$item->reference}",
                ])->save();
            }

            $appointment = $this->open($item, $employee, $data, $actor);

            return ['appointment' => $appointment, 'ended' => $current];
        });
    }

    /**
     * A step increment: the current period ends the day before, and the
     * next opens at the new step on the same item and status.
     */
    public function stepIncrement(PlantillaAppointment $current, int $step, string $date, ?string $remarks, User $actor): PlantillaAppointment
    {
        $from = Carbon::parse($date)->startOfDay();

        if (! $current->isOpen()) {
            throw ValidationException::withMessages(['step' => 'Only the current holder gets a step increment.']);
        }
        if (! $current->isComplete()) {
            throw ValidationException::withMessages(['step' => 'Complete this link first (missing '.implode(', ', $current->missingDetails()).').']);
        }
        if ($step <= $current->step) {
            throw ValidationException::withMessages(['step' => "The new step must be above step {$current->step}."]);
        }
        if ($from->lte($current->date_from)) {
            throw ValidationException::withMessages(['date_from' => 'The increment must take effect after the current period began.']);
        }

        return DB::transaction(function () use ($current, $step, $from, $remarks, $actor) {
            $current->forceFill(['date_to' => $from->copy()->subDay(), 'end_reason' => 'Step increment'])->save();

            return $this->open($current->item, $current->employee, [
                'step' => $step,
                'status' => $current->status->value,
                'nature' => AppointmentNature::StepIncrement->value,
                'date_from' => $from->toDateString(),
                'remarks' => $remarks,
            ], $actor);
        });
    }

    /**
     * The holder leaves the item (separation, or moving to an item not on
     * this plantilla).
     */
    public function vacate(PlantillaAppointment $appointment, string $date, string $reason): void
    {
        if (! $appointment->isOpen()) {
            throw ValidationException::withMessages(['date_to' => 'This period has already ended.']);
        }
        if ($appointment->date_from && Carbon::parse($date)->lt($appointment->date_from)) {
            throw ValidationException::withMessages(['date_to' => 'The last day can\'t be before the period began.']);
        }

        $appointment->forceFill(['date_to' => $date, 'end_reason' => $reason])->save();
    }

    /**
     * Corrects a period's details (a typo in the step, status, nature,
     * dates or remarks). Returns the changed fields.
     *
     * @param  array<string, mixed>  $data  validated
     * @return array<int, string>
     */
    public function correct(PlantillaAppointment $appointment, array $data): array
    {
        $appointment->fill(collect($data)->only(['step', 'status', 'nature', 'date_from', 'date_to', 'end_reason', 'remarks'])->all());

        if ($appointment->date_to && $appointment->date_to->lt($appointment->date_from)) {
            throw ValidationException::withMessages(['date_to' => 'The last day can\'t be before the period began.']);
        }

        $changed = array_values(array_diff(array_keys($appointment->getDirty()), ['updated_at']));

        DB::transaction(function () use ($appointment) {
            $appointment->save();
            $this->followHolder($appointment);
        });

        return $changed;
    }

    /**
     * Removes a period recorded by mistake. If it ended the holder's
     * previous period (a promotion, a step increment), that one is
     * reopened, as long as its item is still free.
     */
    public function remove(PlantillaAppointment $appointment): ?PlantillaAppointment
    {
        return DB::transaction(function () use ($appointment) {
            $appointment->delete();

            // An incomplete link never ended an earlier period.
            if (! $appointment->date_from) {
                return null;
            }

            $previous = PlantillaAppointment::query()
                ->where('employee_id', $appointment->employee_id)
                ->whereDate('date_to', $appointment->date_from->copy()->subDay())
                ->first();

            if (! $appointment->isOpen() || ! $previous) {
                return null;
            }

            $itemTaken = PlantillaAppointment::query()->where('plantilla_item_id', $previous->plantilla_item_id)->whereNull('date_to')->exists();
            $holdsAnother = PlantillaAppointment::query()->where('employee_id', $previous->employee_id)->whereNull('date_to')->exists();

            if ($itemTaken || $holdsAnother) {
                return null;
            }

            $previous->forceFill(['date_to' => null, 'end_reason' => null])->save();
            $this->followHolder($previous);

            return $previous;
        });
    }

    // ── Reading ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function presentItem(PlantillaItem $item): array
    {
        $holder = $item->incumbency?->setRelation('item', $item);

        return [
            'id' => $item->id,
            'item_number' => $item->item_number,
            'position_title' => $item->position_title,
            'salary_grade' => $item->salary_grade,
            'level' => $item->level?->value,
            'level_label' => $item->level?->label(),
            'organizational_unit_id' => $item->organizational_unit_id,
            'office' => $item->organizationalUnit?->short_name ?: $item->organizationalUnit?->name,
            'authority' => $item->authority,
            'remarks' => $item->remarks,
            'abolished' => $item->isAbolished(),
            'abolished_on' => $item->abolished_on?->toDateString(),
            'abolition_authority' => $item->abolition_authority,
            'status' => $item->isAbolished() ? 'abolished' : ($holder ? 'filled' : 'vacant'),
            'holder' => $holder ? [
                'appointment_id' => $holder->id,
                'employee_id' => $holder->employee_id,
                'name' => $holder->employee->user->name,
                'step' => $holder->step,
                'status_label' => $holder->status?->label(),
                'since' => $holder->date_from?->toDateString(),
                'missing' => $holder->missingDetails(),
            ] : null,
            // Priced at the holder's step, or step 1 for a vacant item.
            'monthly_salary' => $item->isAbolished() || $item->salary_grade === null || ($holder && $holder->step === null)
                ? null
                : $this->schedules->monthlySalary($item->salary_grade, $holder->step ?? 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAppointment(PlantillaAppointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'employee_id' => $appointment->employee_id,
            'name' => $appointment->employee->user->name,
            'item_id' => $appointment->plantilla_item_id,
            'item_number' => $appointment->item->item_number,
            'position_title' => $appointment->item->position_title,
            'salary_grade' => $appointment->item->salary_grade,
            'step' => $appointment->step,
            'status' => $appointment->status?->value,
            'status_label' => $appointment->status?->label(),
            'nature' => $appointment->nature?->value,
            'nature_label' => $appointment->nature?->label(),
            'date_from' => $appointment->date_from?->toDateString(),
            'date_to' => $appointment->date_to?->toDateString(),
            'end_reason' => $appointment->end_reason,
            'remarks' => $appointment->remarks,
            'current' => $appointment->isOpen(),
            'missing' => $appointment->missingDetails(),
            // Priced by the schedule in force when the period began.
            'monthly_salary' => $appointment->isComplete()
                ? $this->schedules->monthlySalary($appointment->item->salary_grade, $appointment->step, $appointment->isOpen() ? null : $appointment->date_from)
                : null,
        ];
    }

    /**
     * One person's periods of service on plantilla items, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function historyOf(Employee $employee): Collection
    {
        return $employee->plantillaAppointments()
            ->with(['item', 'employee.user'])
            ->orderByDesc('date_from')->orderByDesc('id')
            ->get()
            ->map(fn (PlantillaAppointment $appointment) => $this->presentAppointment($appointment));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function open(PlantillaItem $item, Employee $employee, array $data, User $actor): PlantillaAppointment
    {
        $appointment = new PlantillaAppointment(collect($data)->only(['step', 'status', 'nature', 'date_from', 'remarks'])->all());
        $appointment->forceFill([
            'plantilla_item_id' => $item->id,
            'employee_id' => $employee->id,
            'recorded_by' => $actor->id,
        ])->save();

        $this->followHolder($appointment);

        return $appointment;
    }

    /**
     * The holder's employment status and account position follow the
     * item they hold now.
     */
    private function followHolder(PlantillaAppointment $appointment): void
    {
        if (! $appointment->isOpen()) {
            return;
        }

        $employee = $appointment->employee()->with('user')->first();
        // An incomplete link leaves whatever status HR already recorded.
        if ($appointment->status) {
            $employee->forceFill(['employment_status' => $appointment->status])->save();
        }
        $employee->user->forceFill(['position' => $appointment->item()->value('position_title')])->save();
    }
}
