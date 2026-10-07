<?php

namespace App\Support\HR\NonPlantilla;

use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\NonPlantillaContract;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff who hold no plantilla item — casual and contractual appointees,
 * Job Order and Contract of Service workers — and their contract
 * periods, for the Non-Plantilla page. Who is listed comes from the
 * employee record's employment status; recording a contract that's
 * running (or the latest) sets that status, so the two stay in step.
 */
class NonPlantilla
{
    /** A contract ending within this many days is flagged for renewal. */
    public const EXPIRING_DAYS = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Everyone in service with a non-plantilla status, with their current
     * (or latest) contract and its history.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        $today = today();

        return Employee::query()
            ->whereIn('employment_status', EmploymentStatus::nonPlantilla())
            ->with(['user.organizationalUnit', 'nonPlantillaContracts' => fn ($query) => $query->with('organizationalUnit')->orderByDesc('date_from')->orderByDesc('id')])
            ->get()
            ->reject(fn (Employee $employee) => $employee->isSeparated())
            ->sortBy(fn (Employee $employee) => $employee->user->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->map(function (Employee $employee) use ($today) {
                $contracts = $employee->nonPlantillaContracts;
                $current = $contracts->first(fn (NonPlantillaContract $contract) => $contract->isCurrentOn($today));
                $shown = $current ?? $contracts->first(fn (NonPlantillaContract $contract) => $contract->date_from->lte($today)) ?? $contracts->first();
                $unit = $shown?->organizationalUnit ?? $employee->user->organizationalUnit;

                return [
                    'employee_id' => $employee->id,
                    'name' => $employee->user->name,
                    'status' => $employee->employment_status->value,
                    'status_label' => $employee->employment_status->label(),
                    'designation' => $shown?->designation ?: $employee->user->position,
                    'office' => $unit?->short_name ?: $unit?->name,
                    'contract' => $shown ? $this->present($shown) : null,
                    'state' => $this->state($current, $shown, $today),
                    'days_left' => $current?->date_to ? (int) $today->diffInDays($current->date_to) : null,
                    'contracts' => $contracts->map(fn (NonPlantillaContract $contract) => $this->present($contract))->values(),
                ];
            })
            ->values();
    }

    /**
     * People a contract can be recorded for: in service, holding no plantilla item now.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function candidates(): array
    {
        return Employee::query()
            ->whereDoesntHave('plantillaAppointments', fn ($query) => $query->whereNull('date_to'))
            ->with('user')->get()
            ->reject(fn (Employee $employee) => $employee->isSeparated())
            ->sortBy(fn (Employee $employee) => $employee->user->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => $employee->employment_status ? "{$employee->user->name} — {$employee->employment_status->label()}" : $employee->user->name,
            ])
            ->values()->all();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(bool $choosingEmployee): array
    {
        return [
            ...($choosingEmployee ? ['employee_id' => ['required', 'integer', 'exists:employees,id']] : []),
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)->only(EmploymentStatus::nonPlantilla())],
            'designation' => ['required', 'string', 'max:150'],
            'organizational_unit_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
            'date_from' => ['required', 'date', 'after:1900-01-01'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rate_basis' => ['nullable', 'required_with:rate', 'in:daily,monthly'],
            'funding_source' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function record(Employee $employee, array $data): NonPlantillaContract
    {
        $employee->loadMissing('user');

        if ($employee->plantillaAppointments()->whereNull('date_to')->exists()) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->user->name} holds a plantilla item; end that appointment first."]);
        }
        $this->guardOverlap($employee, $data, null);

        return DB::transaction(function () use ($employee, $data) {
            $contract = $employee->nonPlantillaContracts()->make($data);
            $contract->forceFill(['recorded_by' => auth()->id()])->save();
            $this->syncStatus($employee);

            $this->audit->record(AuditEvent::NonPlantillaContractRecorded, "Recorded a {$contract->employment_status->label()} contract of {$employee->user->name}", $employee, [
                'contract_id' => $contract->id, 'designation' => $contract->designation,
                'date_from' => $contract->date_from->toDateString(), 'date_to' => $contract->date_to?->toDateString(),
            ]);

            return $contract;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function update(NonPlantillaContract $contract, array $data): void
    {
        $employee = $contract->employee()->with('user')->first();
        $this->guardOverlap($employee, $data, $contract);

        DB::transaction(function () use ($contract, $employee, $data) {
            $contract->fill($data)->save();
            $changes = $this->audit->changes($contract);
            $this->syncStatus($employee);

            if ($changes !== []) {
                $this->audit->record(AuditEvent::NonPlantillaContractUpdated, "Updated a contract of {$employee->user->name}", $employee, ['contract_id' => $contract->id, 'changes' => $changes]);
            }
        });
    }

    public function remove(NonPlantillaContract $contract): void
    {
        $employee = $contract->employee()->with('user')->first();

        DB::transaction(function () use ($contract, $employee) {
            $contract->delete();
            $this->syncStatus($employee);

            $this->audit->record(AuditEvent::NonPlantillaContractRemoved, "Removed a contract of {$employee->user->name}", $employee, [
                'contract_id' => $contract->id, 'designation' => $contract->designation, 'date_from' => $contract->date_from->toDateString(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(NonPlantillaContract $contract): array
    {
        $unit = $contract->organizationalUnit;

        return [
            'id' => $contract->id,
            'employment_status' => $contract->employment_status->value,
            'status_label' => $contract->employment_status->label(),
            'designation' => $contract->designation,
            'organizational_unit_id' => $contract->organizational_unit_id,
            'office' => $unit?->short_name ?: $unit?->name,
            'date_from' => $contract->date_from->toDateString(),
            'date_to' => $contract->date_to?->toDateString(),
            'rate' => $contract->rate !== null ? (float) $contract->rate : null,
            'rate_basis' => $contract->rate_basis,
            'funding_source' => $contract->funding_source,
            'reference' => $contract->reference,
            'remarks' => $contract->remarks,
        ];
    }

    /** current / expiring (ends within EXPIRING_DAYS) / ended / upcoming / none. */
    private function state(?NonPlantillaContract $current, ?NonPlantillaContract $shown, Carbon $today): string
    {
        return match (true) {
            $current !== null => $current->date_to && $current->date_to->lte($today->copy()->addDays(self::EXPIRING_DAYS)) ? 'expiring' : 'current',
            $shown === null => 'none',
            $shown->date_from->gt($today) => 'upcoming',
            default => 'ended',
        };
    }

    /**
     * Two contracts of one person can't overlap.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardOverlap(Employee $employee, array $data, ?NonPlantillaContract $except): void
    {
        $from = Carbon::parse($data['date_from']);
        $to = isset($data['date_to']) ? Carbon::parse($data['date_to']) : null;

        $overlap = $employee->nonPlantillaContracts()
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->where(fn ($query) => $query->whereNull('date_to')->orWhereDate('date_to', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('date_from', '<=', $to))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['date_from' => 'This period overlaps another contract of theirs.']);
        }
    }

    /** The employee record's status follows the running contract, else the latest one. */
    private function syncStatus(Employee $employee): void
    {
        $contracts = $employee->nonPlantillaContracts()->orderByDesc('date_from')->get();
        $contract = $contracts->first(fn (NonPlantillaContract $contract) => $contract->isCurrentOn(today())) ?? $contracts->first();

        if ($contract && $employee->employment_status !== $contract->employment_status) {
            $employee->forceFill(['employment_status' => $contract->employment_status])->save();
        }
    }
}
