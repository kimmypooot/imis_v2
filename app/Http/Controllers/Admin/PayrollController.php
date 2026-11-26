<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\PayComponentKind;
use App\Enums\PayslipRequestStatus;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Models\EmployeeLoan;
use App\Models\PayComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\PayslipRequest;
use App\Support\HR\Payroll\DeductionNotices;
use App\Support\HR\Payroll\GeneralPayrollSheet;
use App\Support\HR\Payroll\Loans;
use App\Support\HR\Payroll\PayrollAccounting;
use App\Support\HR\Payroll\Payrolls;
use App\Support\HR\Payroll\PayslipApprovers;
use App\Support\HR\Payroll\Remittances;
use App\Support\Settings\Settings;
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
        $run->load(['creator', 'finalizer', 'submitter', 'returner']);
        $canManage = $request->user()->can('manage', PayrollRun::class);
        $canEnterTax = $request->user()->can('enterTax', PayrollRun::class);

        return Inertia::render('Admin/HR/Payroll/Show', [
            'run' => $payrolls->presentRun($run),
            'entries' => $run->entries()->with('lines.adjuster', 'employee.user')->orderBy('name')->get()
                ->map(fn (PayrollEntry $entry) => [
                    ...$payrolls->presentEntry($entry, withLines: true),
                    'own' => $entry->employee->user_id === $request->user()->id,
                    'photo_url' => $entry->employee->user?->profile_photo_url,
                ]),
            'leftOut' => $payrolls->leftOut($run),
            'canManage' => $canManage,
            'canEnterTax' => $canEnterTax,
            // Whether the payroll list, profiles and workbook check are open to them (Accounting may only have this page).
            'canViewAll' => $request->user()->hasAnyPermission([PermissionName::PayrollView->value, PermissionName::PayrollManage->value]),
            'reviewers' => app(PayrollAccounting::class)->reviewers()->pluck('name'),
            // Last month's withholding tax per employee, as a guide for Accounting.
            'previousTax' => $canEnterTax ? $payrolls->previousWithholdingTax($run) : (object) [],
            'components' => $canManage ? PayComponent::query()->active()->ordered()->where('kind', '!=', PayComponentKind::Loan->value)->get()
                ->map(fn (PayComponent $component) => ['value' => $component->id, 'label' => $component->name, 'kind' => $component->kind->isDeduction() ? 'deduction' : 'earning'])
                : [],
            'minimumNet' => Payrolls::MINIMUM_NET_PAY,
        ]);
    }

    /** What the month's payroll owes GSIS, PhilHealth, Pag-IBIG, the Welfare Fund and the BIR. */
    public function remittances(PayrollRun $run, Payrolls $payrolls, Remittances $remittances): Response
    {
        $this->authorize('view', $run);

        return Inertia::render('Admin/HR/Payroll/Remittances', [
            'run' => $payrolls->presentRun($run),
            'sections' => $remittances->forRun($run),
        ]);
    }

    /** The General Payroll for signing and printing (landscape, long bond). */
    public function generalPayroll(PayrollRun $run, Payrolls $payrolls, GeneralPayrollSheet $sheet): Response
    {
        $this->authorize('view', $run);
        $run->load(['creator', 'finalizer']);

        return Inertia::render('Admin/HR/Payroll/GeneralPayroll', [
            'run' => $payrolls->presentRun($run),
            'sheet' => $sheet->build($run),
            'organization' => Settings::get('organization_name'),
            'address' => Settings::get('office_address'),
        ]);
    }

    public function regenerate(PayrollRun $run, Payrolls $payrolls): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $payrolls->generate($run);
        $this->audit()->record(AuditEvent::PayrollRegenerated, "Regenerated the {$run->label}", $run, ['entries' => $run->entries()->count()]);

        return back()->with('status', "Payroll for {$run->period} regenerated from pay profiles.");
    }

    /** HRD → MSD: the Accountant III reviews it and enters withholding tax. */
    public function submit(Request $request, PayrollRun $run, Payrolls $payrolls, PayrollAccounting $accounting): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $payrolls->submitToAccounting($run, $request->user());
        $this->audit()->record(AuditEvent::PayrollSubmitted, "Submitted the {$run->label} to Accounting", $run);
        $told = $accounting->notifySubmitted($run, $request->user());

        $names = $accounting->reviewers()->pluck('name');
        if ($told === 0) {
            return back()->with('error', "Payroll for {$run->period} submitted, but nobody in Accounting can receive it: no one holds the ".PayrollAccounting::ACCOUNTANT_TITLE.' item on the Plantilla, and no one has the Accountant role.');
        }

        return back()->with('status', "Payroll for {$run->period} submitted to Accounting ({$names->join(', ', ' and ')}) for review and withholding tax.");
    }

    public function withholdingTax(Request $request, PayrollRun $run, Payrolls $payrolls): RedirectResponse
    {
        $this->authorize('enterTax', PayrollRun::class);
        $data = $request->validate([
            'taxes' => ['required', 'array'],
            'taxes.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $changed = $payrolls->enterWithholdingTax($run, $data['taxes'], $request->user());
        if ($changed > 0) {
            $this->audit()->record(AuditEvent::PayrollTaxEntered, "Entered withholding tax on the {$run->label}", $run, ['entries' => $changed]);
        }

        return back()->with('status', $changed > 0 ? "Withholding tax saved for {$changed} ".str('person')->plural($changed).'.' : 'No withholding tax changed.');
    }

    /** MSD → HRD, to finalize. */
    public function returnToHrd(Request $request, PayrollRun $run, Payrolls $payrolls, PayrollAccounting $accounting): RedirectResponse
    {
        $this->authorize('enterTax', PayrollRun::class);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $payrolls->returnToHrd($run, $request->user(), $data['note'] ?? null);
        $this->audit()->record(AuditEvent::PayrollReturned, "Returned the {$run->label} to HRD", $run, ['with_note' => filled($data['note'] ?? null)]);
        $accounting->notifyReturned($run, $request->user());

        return back()->with('status', "Payroll for {$run->period} returned to HRD.");
    }

    public function finalize(Request $request, PayrollRun $run, Payrolls $payrolls, DeductionNotices $notices): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $payrolls->finalize($run, $request->user());
        $this->audit()->record(AuditEvent::PayrollFinalized, "Finalized the {$run->label}", $run, ['entries' => $run->entries()->count()]);
        $notices->afterFinalize($run);

        return back()->with('status', "Payroll for {$run->period} finalized. Loans count it as paid, and staff can request their payslips.");
    }

    public function reopen(Request $request, PayrollRun $run, Payrolls $payrolls): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $payrolls->reopen($run, $data['reason']);
        $approved = PayslipRequest::query()->whereIn('payroll_entry_id', $run->entries()->select('id'))
            ->where('status', PayslipRequestStatus::Approved)->count();
        $this->audit()->record(AuditEvent::PayrollReopened, "Reopened the {$run->label}: {$data['reason']}", $run, ['approved_payslips' => $approved, 'reason' => $data['reason']]);

        return back()->with('status', "Payroll for {$run->period} reopened; it is back with HRD, withholding tax kept.".($approved ? " {$approved} approved payslip(s) will show the corrected figures once it's finalized again." : ''));
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

        $line = $payrolls->addLine($entry, $data, $request->user());
        $this->audit()->record(AuditEvent::PayrollAdjusted, "Added {$line->label} to the {$entry->label}", $entry, ['action' => 'line_added', 'kind' => $line->kind, 'reason' => $data['reason'] ?? null]);

        return back()->with('status', "{$line->label} added for {$entry->name}.");
    }

    public function updateLine(Request $request, PayrollRun $run, PayrollEntry $entry, PayrollLine $line, Payrolls $payrolls): RedirectResponse
    {
        $this->authorizeEntry($entry);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:9999999'], 'reason' => ['nullable', 'string', 'max:500']]);

        $payrolls->updateLine($line, (float) $data['amount'], $request->user(), $data['reason'] ?? null);
        $this->audit()->record(AuditEvent::PayrollAdjusted, "Overrode {$line->label} on the {$entry->label}", $entry, ['action' => 'line_changed', 'reason' => $data['reason'] ?? null]);

        return back()->with('status', "{$line->label} changed for {$entry->name}.");
    }

    public function destroyLine(PayrollRun $run, PayrollEntry $entry, PayrollLine $line, Payrolls $payrolls): RedirectResponse
    {
        $this->authorizeEntry($entry);

        $payrolls->removeLine($line);
        $this->audit()->record(AuditEvent::PayrollAdjusted, "Removed {$line->label} from the {$entry->label}", $entry, ['action' => 'line_removed']);

        return back()->with('status', "{$line->label} removed for {$entry->name}.");
    }

    public function cancelEntry(Request $request, PayrollRun $run, PayrollEntry $entry, Payrolls $payrolls): RedirectResponse
    {
        $this->authorizeEntry($entry);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $payrolls->cancelEntry($entry, $data['reason'], $request->user());
        $this->audit()->record(AuditEvent::PayslipCancelled, "Cancelled the {$entry->label}: {$data['reason']}", $entry, ['reason' => $data['reason']]);

        return back()->with('status', "The payslip of {$entry->name} was cancelled. It stays on the payroll for the record.");
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
