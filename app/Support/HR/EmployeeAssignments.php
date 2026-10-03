<?php

namespace App\Support\HR;

use App\Enums\AssignmentKind;
use App\Enums\EmployeeDocumentType;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * HR-1b: the only writer of employees' assignment history (moves between
 * Regional Support Units and Field Offices).
 *
 * The office rule, agreed with HR: **only a reassignment moves the
 * account's office**, once, when it takes effect — at once if it is dated
 * today or earlier, otherwise on its date (`applyDue()`, scheduled daily).
 * Details and designations leave the person in their mother unit; a
 * return to the mother unit ends any open detail. Correcting or removing
 * a reassignment re-settles the office (back to where they were, if it
 * was the one that moved them). Recording anything else never touches
 * the office, so an office set by hand stays as it is.
 *
 * A scanned order goes into the 201 file as an Office Order
 * (EmployeeDocuments), so it follows the 201 file's access and audit
 * rules.
 */
class EmployeeAssignments
{
    public function __construct(private readonly EmployeeDocuments $documents) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(Employee $employee): array
    {
        return [
            'kind' => ['required', new Enum(AssignmentKind::class)],
            'from_unit_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
            'to_unit_id' => ['required', 'integer', 'exists:organizational_units,id', $this->movesSomewhere($employee)],
            'effective_date' => ['required', 'date', 'after:1900-01-01'],
            'end_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'order_number' => ['nullable', 'string', 'max:100'],
            'order_date' => ['nullable', 'date', 'after:1900-01-01'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'scan' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.EmployeeDocuments::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'kind' => 'kind of movement',
            'from_unit_id' => 'from office',
            'to_unit_id' => 'to office',
            'effective_date' => 'effective date',
            'end_date' => 'end date',
            'order_number' => 'order number',
            'order_date' => 'order date',
            'scan' => 'scanned order',
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     * @return array{assignment: EmployeeAssignment, document: ?EmployeeDocument, office: ?array{from: ?string, to: ?string}}
     */
    public function record(Employee $employee, array $data, User $actor): array
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $assignment = new EmployeeAssignment($this->fields($data));
            $assignment->from_unit_id ??= $employee->user->organizational_unit_id;
            $assignment->forceFill(['employee_id' => $employee->id, 'recorded_by' => $actor->id])->save();

            if ($assignment->kind === AssignmentKind::ReturnToMotherUnit) {
                $this->endOpenDetails($assignment);
            }

            $document = $this->attachScan($assignment, $data['scan'] ?? null, $actor);
            $office = $assignment->kind->movesOffice() ? $this->settle($assignment) : null;

            return ['assignment' => $assignment, 'document' => $document, 'office' => $office];
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated
     * @return array{changed: array<int, string>, document: ?EmployeeDocument, office: ?array{from: ?string, to: ?string}}
     */
    public function update(EmployeeAssignment $assignment, array $data, User $actor): array
    {
        return DB::transaction(function () use ($assignment, $data, $actor) {
            $wasReassignment = $assignment->kind->movesOffice();
            $assignment->fill($this->fields($data));
            $changed = array_keys($assignment->getDirty());
            $assignment->save();

            $document = $this->attachScan($assignment, $data['scan'] ?? null, $actor);
            if ($document) {
                $changed[] = 'scan';
            }

            $office = $wasReassignment || $assignment->kind->movesOffice() ? $this->settle($assignment, $wasReassignment) : null;

            return ['changed' => array_values(array_diff($changed, ['updated_at'])), 'document' => $document, 'office' => $office];
        });
    }

    /**
     * Soft delete. Removing the reassignment that moved someone puts them
     * back where it moved them from (or where the one before it put them).
     *
     * @return ?array{from: ?string, to: ?string}
     */
    public function delete(EmployeeAssignment $assignment): ?array
    {
        return DB::transaction(function () use ($assignment) {
            $assignment->delete();

            return $assignment->kind->movesOffice() ? $this->settle($assignment, true) : null;
        });
    }

    /**
     * Moves the offices of reassignments that have come into effect since
     * they were recorded (future-dated ones). Each moves the office once.
     */
    public function applyDue(): int
    {
        $due = EmployeeAssignment::query()->reassignments()
            ->whereNull('applied_at')
            ->whereDate('effective_date', '<=', today())
            ->with('employee.user')
            ->orderBy('effective_date')->orderBy('id')
            ->get();

        foreach ($due as $assignment) {
            DB::transaction(fn () => $this->settle($assignment));
        }

        return $due->count();
    }

    /**
     * One person's history, newest first, plus where they are today.
     *
     * @return array{entries: Collection<int, array<string, mixed>>, current: array<string, mixed>}
     */
    public function timeline(Employee $employee, ?User $viewer): array
    {
        $entries = $employee->assignments()
            ->with(['fromUnit', 'toUnit', 'recorder:id,name', 'document'])
            ->orderByDesc('effective_date')->orderByDesc('id')
            ->get();

        $today = today();
        $reassignments = $entries->where('kind', AssignmentKind::Reassignment)->sortBy([['effective_date', 'asc'], ['id', 'asc']])->values();
        $currentReassignment = $reassignments->filter(fn ($entry) => $entry->effective_date->lte($today))->last();

        $rows = $entries->map(function (EmployeeAssignment $entry) use ($reassignments, $currentReassignment, $today, $viewer) {
            $end = $entry->end_date;
            if ($entry->kind === AssignmentKind::Reassignment) {
                $next = $reassignments->first(fn ($other) => $other->effective_date->gt($entry->effective_date));
                $end = $next?->effective_date->copy()->subDay();
            }

            $active = $entry->kind->hasEndDate() && $entry->effective_date->lte($today) && ($entry->end_date === null || $entry->end_date->gte($today));

            return [
                ...$this->present($entry, $viewer),
                'end_date' => $end?->toDateString(),
                'end_is_derived' => $entry->kind === AssignmentKind::Reassignment && $end !== null,
                'current' => $entry->is($currentReassignment) || $active,
                'upcoming' => $entry->effective_date->gt($today),
            ];
        })->values();

        return [
            'entries' => $rows,
            'current' => [
                'office' => $this->unitName($employee->user->organizationalUnit),
                'details' => $rows->where('kind', AssignmentKind::Detail->value)->where('current', true)->values(),
                'designations' => $rows->where('kind', AssignmentKind::Designation->value)->where('current', true)->values(),
            ],
        ];
    }

    /**
     * Where each person was on a date (HR-5's station column, and the
     * "as of" report): the office the reassignments put them in then, and
     * any detail running that day.
     *
     * @param  Collection<int, Employee>  $employees  with user.organizationalUnit
     * @return Collection<int, array<string, mixed>>
     */
    public function stationsOn(Collection $employees, Carbon $date): Collection
    {
        $history = EmployeeAssignment::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereIn('kind', [AssignmentKind::Reassignment, AssignmentKind::Detail])
            ->with(['fromUnit', 'toUnit'])
            ->orderBy('effective_date')->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        return $employees->map(function (Employee $employee) use ($history, $date) {
            $own = $history->get($employee->id, collect());
            $moves = $own->where('kind', AssignmentKind::Reassignment);
            $last = $moves->filter(fn ($move) => $move->effective_date->lte($date))->last();
            $nextAfter = $moves->first(fn ($move) => $move->effective_date->gt($date));

            $office = $last?->toUnit ?? ($nextAfter ? $nextAfter->fromUnit : $employee->user->organizationalUnit);
            $detail = $own->where('kind', AssignmentKind::Detail)
                ->filter(fn ($entry) => $entry->effective_date->lte($date) && ($entry->end_date === null || $entry->end_date->gte($date)))
                ->last();

            return [
                'employee_id' => $employee->id,
                'name' => $employee->user->name,
                'position' => $employee->user->position,
                'office' => $this->unitName($office),
                'office_id' => $office?->id,
                'detailed_to' => $this->unitName($detail?->toUnit),
                'detailed_to_id' => $detail?->to_unit_id,
                // The order behind where they are: a running detail, else the last reassignment.
                'moved_on' => ($detail ?? $last)?->effective_date->toDateString(),
                'order_number' => ($detail ?? $last)?->order_number,
                'move_kind' => ($detail ?? $last)?->kind->label(),
                'separated' => $employee->separation_date !== null && $employee->separation_date->lte($date),
            ];
        })->values();
    }

    /**
     * The office the reassignment history puts someone in on a date (the
     * Service Record's station): the latest reassignment by then, else
     * where the first later one moved them from, else their office now.
     */
    public function officeOn(Employee $employee, Carbon $date): ?OrganizationalUnit
    {
        $moves = $employee->assignments()->reassignments()->with(['fromUnit', 'toUnit'])
            ->orderBy('effective_date')->orderBy('id')->get();

        $last = $moves->filter(fn ($move) => $move->effective_date->lte($date))->last();
        $next = $moves->first(fn ($move) => $move->effective_date->gt($date));

        return $last?->toUnit ?? ($next ? $next->fromUnit : $employee->user->organizationalUnit);
    }

    /**
     * The dates on which someone's reassignments took effect (Service
     * Record lines break there).
     *
     * @return Collection<int, Carbon>
     */
    public function reassignmentDates(Employee $employee): Collection
    {
        return $employee->assignments()->reassignments()->orderBy('effective_date')->pluck('effective_date');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(EmployeeAssignment $entry, ?User $viewer): array
    {
        return [
            'id' => $entry->id,
            'kind' => $entry->kind->value,
            'kind_label' => $entry->kind->label(),
            'moves_office' => $entry->kind->movesOffice(),
            'from_unit_id' => $entry->from_unit_id,
            'from_unit' => $this->unitName($entry->fromUnit),
            'to_unit_id' => $entry->to_unit_id,
            'to_unit' => $this->unitName($entry->toUnit),
            'effective_date' => $entry->effective_date->toDateString(),
            'end_date' => $entry->end_date?->toDateString(),
            'order_number' => $entry->order_number,
            'order_date' => $entry->order_date?->toDateString(),
            'remarks' => $entry->remarks,
            'recorded_by' => $entry->recorder?->name,
            // The scan lives in the 201 file: only those who may see it get a link.
            'scan' => $entry->document && $viewer?->can('view', $entry->document)
                ? ['id' => $entry->document->id, 'url' => route('hr.documents.show', $entry->document)]
                : null,
            'has_scan' => $entry->document_id !== null,
        ];
    }

    /**
     * Settles the account's office after a reassignment was recorded,
     * corrected or removed: the latest reassignment in effect decides; if
     * none is left, the changed one's starting office does. Marks the
     * reassignment applied once it is in effect.
     *
     * @return ?array{from: ?string, to: ?string} the move, when there was one
     */
    private function settle(EmployeeAssignment $changed, bool $mayRevert = false): ?array
    {
        $employee = $changed->employee()->with('user.organizationalUnit')->first();

        if (! $changed->trashed() && $changed->kind->movesOffice() && $changed->effective_date->lte(today())) {
            $changed->forceFill(['applied_at' => $changed->applied_at ?? now()])->saveQuietly();
        }

        $latest = $employee->assignments()->reassignments()
            ->whereDate('effective_date', '<=', today())
            ->orderByDesc('effective_date')->orderByDesc('id')
            ->first();

        $target = $latest?->to_unit_id ?? ($mayRevert ? $changed->from_unit_id : null);
        $user = $employee->user;

        if ($target === null || $user->organizational_unit_id === $target) {
            return null;
        }

        $from = $this->unitName($user->organizationalUnit);
        $user->forceFill(['organizational_unit_id' => $target])->save();

        return ['from' => $from, 'to' => $this->unitName(OrganizationalUnit::query()->find($target))];
    }

    /**
     * A reassignment or detail has to go somewhere else than where the
     * person starts from (the given office, or their current one).
     */
    private function movesSomewhere(Employee $employee): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($employee) {
            $kind = AssignmentKind::tryFrom((string) request()->input('kind'));
            $from = (int) (request()->input('from_unit_id') ?: $employee->user->organizational_unit_id);

            if (in_array($kind, [AssignmentKind::Reassignment, AssignmentKind::Detail], true) && (int) $value === $from) {
                $fail('The to office must be different from the office they move from.');
            }
        };
    }

    /**
     * A return to the mother unit ends any detail still running on its date.
     */
    private function endOpenDetails(EmployeeAssignment $return): void
    {
        EmployeeAssignment::query()
            ->where('employee_id', $return->employee_id)
            ->where('kind', AssignmentKind::Detail)
            ->whereDate('effective_date', '<', $return->effective_date)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $return->effective_date))
            ->get()
            ->each(fn (EmployeeAssignment $detail) => $detail->forceFill(['end_date' => $return->effective_date->copy()->subDay()])->save());
    }

    private function attachScan(EmployeeAssignment $assignment, mixed $file, User $actor): ?EmployeeDocument
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $document = $this->documents->store($assignment->employee, $file, [
            'document_type' => EmployeeDocumentType::OfficeOrder,
            'title' => $assignment->kind->label().' order'.($assignment->order_number ? ' No. '.$assignment->order_number : ''),
            'document_date' => $assignment->order_date ?? $assignment->effective_date,
            'remarks' => 'Attached to the '.mb_strtolower($assignment->kind->label()).' effective '.$assignment->effective_date->toDateString().'.',
        ], $actor);

        $assignment->forceFill(['document_id' => $document->id])->save();

        return $document;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = collect($data)->only(['kind', 'from_unit_id', 'to_unit_id', 'effective_date', 'end_date', 'order_number', 'order_date', 'remarks'])->all();

        // Only details and designations end on their own date.
        if (! AssignmentKind::from($fields['kind'])->hasEndDate()) {
            $fields['end_date'] = null;
        }

        return $fields;
    }

    private function unitName(?OrganizationalUnit $unit): ?string
    {
        return $unit?->short_name ?: $unit?->name;
    }
}
