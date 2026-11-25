<?php

namespace App\Support\HR\Saln;

use App\Enums\AppointmentNature;
use App\Enums\EmploymentStatus;
use App\Enums\SalnStatus;
use App\Enums\SalnType;
use App\Models\Employee;
use App\Models\NonPlantillaContract;
use App\Models\PlantillaAppointment;
use App\Models\Saln;
use App\Models\SalnExemption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who must file which SALN, by when, and where each one is.
 *
 * - Required: permanent, temporary, coterminous and contractual staff.
 *   Casual, job order and contract-of-service staff are not ("casual or
 *   temporary workers" are exempt under the 2025 Omnibus Rules; JO/COS are
 *   not government employees). HRD can exempt anyone else for a year.
 * - Annual: everyone required on December 31 (their status that day, from
 *   their appointment or contract then), due April 30. Years due before
 *   go-live were filed on paper and aren't tracked.
 * - Entry and exit: within 30 days of assumption (first appointment or
 *   re-employment) or separation, from go-live.
 *
 * Each row: employee, type, as_of, due, saln (or null), exempt (or null),
 * state: filed | in_progress | missing | late | exempt.
 */
class SalnObligations
{
    public const REQUIRED = [
        EmploymentStatus::Permanent, EmploymentStatus::Temporary,
        EmploymentStatus::Coterminous, EmploymentStatus::Contractual,
    ];

    public const DAYS_TO_FILE = 30;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function annual(int $year, ?int $employeeId = null): Collection
    {
        $asOf = CarbonImmutable::create($year, 12, 31);
        $due = CarbonImmutable::create($year + 1, 4, 30);

        if ($due->lt($this->goLive())) {
            return collect();
        }

        return $this->employees($employeeId)
            ->filter(fn (Employee $employee) => $this->inServiceOn($employee, $asOf) && $this->requiredOn($employee, $asOf))
            ->map(fn (Employee $employee) => $this->row($employee, SalnType::Annual, $asOf, $due))
            ->values();
    }

    /**
     * Entry and exit SALNs whose assumption or separation falls between the
     * two dates (and on or after go-live).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entryAndExit(CarbonImmutable $from, CarbonImmutable $to, ?int $employeeId = null): Collection
    {
        $from = $from->max($this->goLive());
        $rows = collect();

        foreach ($this->employees($employeeId) as $employee) {
            foreach ($this->assumptions($employee) as $assumed) {
                if ($assumed->betweenIncluded($from, $to) && $this->requiredOn($employee, $assumed)) {
                    $rows->push($this->row($employee, SalnType::Entry, $assumed, $assumed->addDays(self::DAYS_TO_FILE)));
                }
            }

            $separated = $employee->separation_date ? CarbonImmutable::parse($employee->separation_date) : null;
            if ($separated?->betweenIncluded($from, $to) && $this->requiredOn($employee, $separated)) {
                $rows->push($this->row($employee, SalnType::Exit, $separated, $separated->addDays(self::DAYS_TO_FILE)));
            }
        }

        return $rows->values();
    }

    /**
     * Everything not yet filed or exempted, for the reminders and the
     * employee's own page: last year's annual SALN and any entry or exit
     * SALN of the past year.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function open(?int $employeeId = null): Collection
    {
        $today = CarbonImmutable::today();

        return $this->annual($today->year - 1, $employeeId)
            ->merge($this->entryAndExit($today->subYear(), $today, $employeeId))
            ->reject(fn (array $row) => in_array($row['state'], ['filed', 'exempt'], true))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function dueFor(Employee $employee): Collection
    {
        return $this->open($employee->id);
    }

    public function requiredOn(Employee $employee, CarbonImmutable $date): bool
    {
        return in_array($this->statusOn($employee, $date), self::REQUIRED, true);
    }

    public function statusOn(Employee $employee, CarbonImmutable $date): ?EmploymentStatus
    {
        $covers = fn ($period) => CarbonImmutable::parse($period->date_from)->lte($date)
            && ($period->date_to === null || CarbonImmutable::parse($period->date_to)->gte($date));

        $appointment = $employee->plantillaAppointments->sortByDesc('date_from')->first($covers);
        if ($appointment instanceof PlantillaAppointment) {
            return $appointment->status;
        }

        $contract = $employee->nonPlantillaContracts->sortByDesc('date_from')->first($covers);
        if ($contract instanceof NonPlantillaContract) {
            return $contract->employment_status;
        }

        return $employee->employment_status instanceof EmploymentStatus
            ? $employee->employment_status
            : EmploymentStatus::tryFrom((string) $employee->employment_status);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Employee $employee, SalnType $type, CarbonImmutable $asOf, CarbonImmutable $due): array
    {
        /** @var ?Saln $saln */
        $saln = $employee->salns->first(fn (Saln $saln) => $saln->type === $type && $saln->reckoning_date->isSameDay($asOf));
        /** @var ?SalnExemption $exempt */
        $exempt = $employee->salnExemptions->firstWhere('year', $asOf->year);

        $state = match (true) {
            $saln?->status === SalnStatus::Filed => 'filed',
            $exempt !== null => 'exempt',
            CarbonImmutable::today()->gt($due) => 'late',
            $saln !== null => 'in_progress',
            default => 'missing',
        };

        return ['employee' => $employee, 'type' => $type, 'as_of' => $asOf, 'due' => $due, 'saln' => $saln, 'exempt' => $exempt, 'state' => $state];
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employees(?int $employeeId = null): Collection
    {
        return Employee::query()
            ->when($employeeId, fn ($query) => $query->whereKey($employeeId))
            ->with([
                'user.organizationalUnit', 'plantillaAppointments', 'nonPlantillaContracts', 'salnExemptions',
                // Only what a row needs: never the encrypted statements.
                'salns' => fn ($query) => $query->select(['id', 'employee_id', 'type', 'reckoning_date', 'status']),
            ])
            ->get();
    }

    private function inServiceOn(Employee $employee, CarbonImmutable $date): bool
    {
        $from = $employee->original_appointment_date ? CarbonImmutable::parse($employee->original_appointment_date) : null;
        $until = $employee->separation_date ? CarbonImmutable::parse($employee->separation_date) : null;

        return ($from === null || $from->lte($date)) && ($until === null || $until->gte($date));
    }

    /**
     * The first day of service, and every re-employment.
     *
     * @return array<int, CarbonImmutable>
     */
    private function assumptions(Employee $employee): array
    {
        $dates = $employee->plantillaAppointments
            ->filter(fn (PlantillaAppointment $appointment) => $appointment->nature === AppointmentNature::Reemployment)
            ->map(fn (PlantillaAppointment $appointment) => CarbonImmutable::parse($appointment->date_from))
            ->all();

        if ($employee->original_appointment_date) {
            $dates[] = CarbonImmutable::parse($employee->original_appointment_date);
        }

        return collect($dates)->unique(fn (CarbonImmutable $date) => $date->toDateString())->values()->all();
    }

    private function goLive(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('saln.go_live'));
    }
}
