<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\DataCorrectionRequest;
use App\Models\Employee;
use App\Support\HR\DataCorrections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service: ask HR to correct a field of your own employee record,
 * and follow your requests. Everyone may; HR decides (see
 * Admin\DataCorrectionController).
 */
class MyDataCorrectionController extends Controller
{
    public function index(Request $request, DataCorrections $corrections): Response
    {
        $employee = $this->employee($request);

        return Inertia::render('Portal/MyDataCorrections', [
            'fields' => DataCorrections::fieldOptions(),
            'current' => $corrections->currentValues($employee),
            'requests' => DataCorrectionRequest::query()->with(['employee.user', 'decider'])
                ->where('employee_id', $employee->id)->latest()->get()
                ->map(fn (DataCorrectionRequest $item) => $corrections->present($item)),
        ]);
    }

    public function store(Request $request, DataCorrections $corrections): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'field' => ['required', 'string'],
            'proposed_value' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $item = $corrections->request($employee, $data['field'], $data['proposed_value'] ?? null, $data['reason']);
        $this->audit()->record(AuditEvent::DataCorrectionRequested, "Requested {$item->label}", $item, ['field' => $item->field]);

        return back()->with('status', "Request {$item->reference_number} was sent to HR.");
    }

    public function cancel(Request $request, DataCorrectionRequest $correction, DataCorrections $corrections): RedirectResponse
    {
        abort_unless($correction->employee_id === $this->employee($request)->id, 403);

        $corrections->cancel($correction);
        $this->audit()->record(AuditEvent::DataCorrectionCancelled, "Cancelled {$correction->label}", $correction);

        return back()->with('status', "Request {$correction->reference_number} was cancelled.");
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'HR hasn\'t set up your employee record yet.');

        return $employee;
    }
}
