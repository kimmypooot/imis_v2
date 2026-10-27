<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Support\HR\Plantilla\Plantilla;
use App\Support\HR\Plantilla\SalarySchedules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * HR-4: who holds a plantilla item — appointing, step increments,
 * vacating, and correcting or removing a period recorded by mistake.
 * Never on your own appointment (PlantillaItemPolicy::appoint), except
 * correcting a period with the `plantilla.correct` override.
 */
class PlantillaAppointmentController extends Controller
{
    public function store(Request $request, PlantillaItem $item, Plantilla $plantilla): RedirectResponse
    {
        $data = $request->validate($plantilla->appointmentRules(), [], $this->attributes());
        $employee = Employee::query()->with('user')->findOrFail($data['employee_id']);
        $this->authorize('appoint', [PlantillaItem::class, $employee]);

        ['appointment' => $appointment, 'ended' => $ended] = $plantilla->appoint($item, $employee, $data, $request->user());

        $this->audit()->record(AuditEvent::PlantillaAppointed, "Appointed {$employee->user->name} to {$item->label}", $appointment, array_filter([
            'nature' => $appointment->nature->value,
            'status' => $appointment->status->value,
            'step' => $appointment->step,
            'ended_item' => $ended?->item->reference,
        ]));

        return back()->with('status', "{$employee->user->name} was appointed to {$item->reference}."
            .($ended ? " Their period on {$ended->item->reference} ended the day before." : ''));
    }

    public function stepIncrement(Request $request, PlantillaItem $item, PlantillaAppointment $appointment, Plantilla $plantilla): RedirectResponse
    {
        $this->authorize('appoint', [PlantillaItem::class, $appointment->employee]);

        $data = $request->validate([
            'step' => ['required', 'integer', 'between:2,'.SalarySchedules::STEPS],
            'date_from' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'salary_override' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
        ], [], $this->attributes());

        $next = $plantilla->stepIncrement($appointment, (int) $data['step'], $data['date_from'], $data['remarks'] ?? null, $request->user(), isset($data['salary_override']) ? (float) $data['salary_override'] : null);

        $this->audit()->record(AuditEvent::PlantillaStepIncrement, "Step increment for {$next->label}", $next, ['from_step' => $appointment->step, 'to_step' => $next->step]);

        return back()->with('status', "Step increment to step {$next->step} recorded.");
    }

    public function vacate(Request $request, PlantillaItem $item, PlantillaAppointment $appointment, Plantilla $plantilla): RedirectResponse
    {
        $this->authorize('appoint', [PlantillaItem::class, $appointment->employee]);

        $data = $request->validate([
            'date_to' => ['required', 'date'],
            'end_reason' => ['required', 'string', 'max:255'],
        ], [], ['date_to' => 'last day', 'end_reason' => 'reason']);

        $plantilla->vacate($appointment, $data['date_to'], $data['end_reason']);

        $this->audit()->record(AuditEvent::PlantillaVacated, "Vacated {$item->label}", $appointment, $data);

        return back()->with('status', "Item {$item->reference} is now vacant.");
    }

    public function update(Request $request, PlantillaItem $item, PlantillaAppointment $appointment, Plantilla $plantilla): RedirectResponse
    {
        $this->authorize('correct', [PlantillaItem::class, $appointment->employee]);

        $data = $request->validate([
            ...$plantilla->appointmentRules(false),
            'date_to' => ['nullable', 'date'],
            'end_reason' => ['nullable', 'required_with:date_to', 'string', 'max:255'],
        ], [], $this->attributes());

        $changed = $plantilla->correct($appointment, $data);

        if ($changed !== []) {
            $own = $appointment->employee->user_id === $request->user()->id;
            $this->audit()->record(AuditEvent::PlantillaAppointmentCorrected, ($own ? 'Corrected their own ' : 'Corrected the ').$appointment->label, $appointment,
                ['fields' => $changed] + ($own ? ['own_record' => true] : []));
        }

        return back()->with('status', 'The period was saved.');
    }

    public function destroy(PlantillaItem $item, PlantillaAppointment $appointment, Plantilla $plantilla): RedirectResponse
    {
        $this->authorize('appoint', [PlantillaItem::class, $appointment->employee]);

        $reopened = $plantilla->remove($appointment);

        $this->audit()->record(AuditEvent::PlantillaAppointmentRemoved, "Removed the {$appointment->label}", $appointment, array_filter([
            'reopened' => $reopened?->item->reference,
        ]));

        return back()->with('status', 'The period was removed.'.($reopened ? " Their period on {$reopened->item->reference} is open again." : ''));
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['employee_id' => 'employee', 'date_from' => 'effective date', 'date_to' => 'last day', 'end_reason' => 'reason'];
    }
}
