<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\PayslipRequestStatus;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;
use App\Support\HR\Payroll\Payrolls;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-8: the printable payslip of one payroll entry — for the employee
 * once their request for it is approved, and for payroll staff
 * (PayrollRunPolicy::viewPayslip). Opening someone else's is audited.
 */
class PayslipController extends Controller
{
    /**
     * HR's override notes (the configured amount, who and why) are for
     * payroll staff; the employee's copy shows only the figures.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function forViewer(array $entry, Request $request): array
    {
        if (! $request->user()->can('viewAny', PayrollRun::class)) {
            $entry['lines'] = array_map(fn (array $line) => array_diff_key($line, array_flip(['original_amount', 'adjust_reason', 'adjusted_by', 'adjusted_at', 'remitted_on'])), $entry['lines']);
            $entry['cancel_reason'] = null;
        }

        return $entry;
    }

    public function show(Request $request, PayrollEntry $entry, Payrolls $payrolls): Response
    {
        $entry->load(['run', 'employee', 'lines']);
        $this->authorize('viewPayslip', [PayrollRun::class, $entry]);

        if ($entry->employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::PayslipViewed, "Viewed the payslip of {$entry->name} for {$entry->run->period}", $entry);
        }

        $approved = PayslipRequest::query()->where('payroll_entry_id', $entry->id)
            ->where('status', PayslipRequestStatus::Approved)->latest('decided_at')->with(['decider', 'actingFor'])->first();

        return Inertia::render('HR/PayslipPrint', [
            'entry' => $this->forViewer($payrolls->presentEntry($entry, withLines: true), $request),
            'run' => [
                'period' => $entry->run->period,
                'first_release_on' => $entry->run->first_release_on?->toDateString(),
                'second_release_on' => $entry->run->second_release_on?->toDateString(),
                'finalized' => $entry->run->isFinalized(),
            ],
            'request' => $approved ? [
                'reference_number' => $approved->reference_number,
                'approved_by' => $approved->decider?->name,
                'acting_for' => $approved->actingFor?->name,
                'approved_at' => $approved->decided_at?->toIso8601String(),
            ] : null,
            'organization' => Settings::get('organization_name'),
        ]);
    }
}
