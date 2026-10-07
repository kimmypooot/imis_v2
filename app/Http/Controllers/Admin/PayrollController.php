<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\PayComponentKind;
use App\Enums\PayslipRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use App\Models\PayComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;
use App\Support\HR\Payroll\Loans;
use App\Support\HR\Payroll\Payrolls;
use App\Support\HR\Payroll\PayslipApprovers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-8: monthly payrolls — the list with monitoring (this month, payslip
 * requests, loans nearly paid), one payroll with its entries, and HR's
 * work on a draft: generating, adjusting lines, finalizing, reopening.
 * Nobody adjusts their own entry.
 */
class PayrollController extends Controller
{
    public function index(Request $request, Payrolls $payrolls, Loans $loans, PayslipApprovers $approvers): Response
    {
        $this->authorize('viewAny', PayrollRun::class);

        $active = EmployeeLoan::query()->with(['employee.user', 'component'])->whereNull('stopped_on')->get();
        $loanRows = $loans->presentMany($active)->where('status', 'active');

        return Inertia::render('Admin/HR/Payroll/Index', [
            'runs' => PayrollRun::query()->with(['creator', 'finalizer'])->orderByDesc('year')->orderByDesc('month')->get()
                ->map(fn (PayrollRun $run) => $payrolls->presentRun($run)),
            'requests' => PayslipRequest::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'loans' => [
                'active' => $loanRows->count(),
                'balance' => round($loanRows->sum('balance'), 2),
                'nearlyPaid' => $loanRows->filter(fn ($loan) => $loan['months_left'] !== null && $loan['months_left'] <= Loans::NEARLY_PAID_MONTHS)
                    ->sortBy('months_left')->values(),
            ],
            'approvers' => $approvers->approvers()->pluck('name'),
            'canManage' => $request->user()->can('manage', PayrollRun::class),
            'nextMonth' => $this->nextMonth(),
        ]);
    }

    public function store(Request $request, Payrolls $payrolls): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);
        $data = $request->validate($payrolls->runRules());

        $run = $payrolls->create($data['month'], $data['remarks'] ?? null, $request->user());
        $this->audit()->record(AuditEvent::PayrollCreated, "Created the {$run->label}", $run, ['entries' => $run->entries()->count()]);

        return to_route('admin.hr.payroll.show', $run)->with('status', "Payroll for {$run->period} drafted: review it, then finalize.");
    }

    public function show(Request $request, PayrollRun $run, Payrolls $payrolls): Response
    {
        $this->authorize('view', $run);
        $run->load(['creator', 'finalizer']);
        $canManage = $request->user()->can('manage', PayrollRun::class);

        return Inertia::render('Admin/HR/Payroll/Show', [
            'run' => $payrolls->presentRun($run),
            'entries' => $run->entries()->with('lines', 'employee')->orderBy('name')->get()
                ->map(fn (PayrollEntry $entry) => [
                    ...$payrolls->presentEntry($entry, withLines: true),
                    'own' => $entry->employee->user_id === $request->user()->id,
                ]),
            'leftOut' => $payrolls->leftOut($run),
            'canManage' => $canManage,
            'components' => $canManage ? PayComponent::query()->active()->ordered()->where('kind', '!=', PayComponentKind::Loan->value)->get()
                ->map(fn (PayComponent $component) => ['value' => $component->id, 'label' => $component->name, 'kind' => $component->kind->isDeduction() ? 'deduction' : 'earning'])
                : [],
            'minimumNet' => Payrolls::MINIMUM_NET_PAY,
        ]);
    }

    public function regenerate(PayrollRun $run, Payrolls $payrolls): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $payrolls->generate($run);
        $this->audit()->record(AuditEvent::PayrollRegenerated, "Regenerated the {$run->label}", $run, ['entries' => $run->entries()->count()]);

        return back()->with('status', "Payroll for {$run->period} regenerated from pay profiles.");
    }

    public function finalize(Request $request, PayrollRun $run, Payrolls $payrolls): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $payrolls->finalize($run, $request->user());
        $this->audit()->record(AuditEvent::PayrollFinalized, "Finalized the {$run->label}", $run, ['entries' => $run->entries()->count()]);

        return back()->with('status', "Payroll for {$run->period} finalized. Loans count it as paid, and staff can request their payslips.");
    }

    public function reopen(PayrollRun $run, Payrolls $payrolls): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $payrolls->reopen($run);
        $approved = PayslipRequest::query()->whereIn('payroll_entry_id', $run->entries()->select('id'))
            ->where('status', PayslipRequestStatus::Approved)->count();
        $this->audit()->record(AuditEvent::PayrollReopened, "Reopened the {$run->label}", $run, ['approved_payslips' => $approved]);

        return back()->with('status', "Payroll for {$run->period} reopened as a draft.".($approved ? " {$approved} approved payslip(s) will show the corrected figures once it's finalized again." : ''));
    }

    public function destroy(PayrollRun $run, Payrolls $payrolls): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $period = $run->period;
        $this->audit()->record(AuditEvent::PayrollDeleted, "Deleted the {$run->label}", $run);
        $payrolls->delete($run);

        return to_route('admin.hr.payroll.index')->with('status', "Draft payroll for {$period} deleted.");
    }

    public function regenerateEntry(PayrollRun $run, PayrollEntry $entry, Payrolls $payrolls): RedirectResponse
    {
        $this->authorizeEntry($entry);

        $payrolls->regenerateEntry($entry);
        $this->audit()->record(AuditEvent::PayrollAdjusted, "Rebuilt the {$entry->label} from the pay profile", $entry, ['action' => 'regenerated']);

        return back()->with('status', "{$entry->name}'s pay was rebuilt from the pay profile.");
    }

    public function storeLine(Request $request, PayrollRun $run, PayrollEntry $entry, Payrolls $payrolls): RedirectResponse
    {
        $this->authorizeEntry($entry);
        $data = $request->validate($payrolls->lineRules());

        $line = $payrolls->addLine($entry, $data);
        $this->audit()->record(AuditEvent::PayrollAdjusted, "Added {$line->label} to the {$entry->label}", $entry, ['action' => 'line_added', 'kind' => $line->kind]);

        return back()->with('status', "{$line->label} added for {$entry->name}.");
    }

    public function updateLine(Request $request, PayrollRun $run, PayrollEntry $entry, PayrollLine $line, Payrolls $payrolls): RedirectResponse
    {
        $this->authorizeEntry($entry);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:9999999']]);

        $payrolls->updateLine($line, (float) $data['amount']);
        $this->audit()->record(AuditEvent::PayrollAdjusted, "Changed {$line->label} on the {$entry->label}", $entry, ['action' => 'line_changed']);

        return back()->with('status', "{$line->label} changed for {$entry->name}.");
    }

    public function destroyLine(PayrollRun $run, PayrollEntry $entry, PayrollLine $line, Payrolls $payrolls): RedirectResponse
    {
        $this->authorizeEntry($entry);

        $payrolls->removeLine($line);
        $this->audit()->record(AuditEvent::PayrollAdjusted, "Removed {$line->label} from the {$entry->label}", $entry, ['action' => 'line_removed']);

        return back()->with('status', "{$line->label} removed for {$entry->name}.");
    }

    private function authorizeEntry(PayrollEntry $entry): void
    {
        $this->authorize('managePayOf', [PayrollRun::class, $entry->employee]);
    }

    /** The month after the latest payroll, or this month if there's none. */
    private function nextMonth(): string
    {
        $latest = PayrollRun::query()->orderByDesc('year')->orderByDesc('month')->first();

        return $latest ? $latest->month()->addMonthNoOverflow()->format('Y-m') : today()->format('Y-m');
    }
}
