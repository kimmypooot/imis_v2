<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\PayComponentKind;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\EmployeePayItem;
use App\Models\PayComponent;
use App\Models\PayrollEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Support\HR\Payroll\DeductionNotices;
use App\Support\HR\Payroll\Loans;
use App\Support\HR\Payroll\PayProfiles;
use App\Support\HR\Payroll\Payrolls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-8: pay profiles — everyone in service with their recurring items and
 * loans at a glance; one person's profile (recurring allowances and
 * deductions, loans with balances, their recent pay); and HR's edits to
 * it. Never your own (separation of duties). Every look at someone else's
 * profile is audited (RA 10173), as with the employee record.
 */
class PayProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PayrollRun::class);

        $employees = Employee::query()->with('user')->whereNull('separation_date')
            ->withCount([
                'payItems as items_count' => fn ($query) => $query->inEffectDuring(today()->startOfMonth()),
                'loans as loans_count' => fn ($query) => $query->whereNull('stopped_on'),
            ])->get()
            ->sortBy(fn (Employee $employee) => $employee->user->name, SORT_NATURAL | SORT_FLAG_CASE)->values();

        return Inertia::render('Admin/HR/Payroll/Profiles', [
            'employees' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'position' => $employee->user->position,
                'items' => $employee->items_count,
                'loans' => $employee->loans_count,
            ]),
        ]);
    }

    public function show(Request $request, Employee $employee, PayProfiles $profiles, Loans $loans, Payrolls $payrolls): Response
    {
        $this->authorize('viewAny', PayrollRun::class);
        $employee->load('user');

        if ($employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::EmployeeViewed, "Viewed the pay profile of {$employee->user->name}", $employee, ['section' => 'pay_profile']);
        }

        return Inertia::render('Admin/HR/Payroll/Profile', [
            'employee' => ['id' => $employee->id, 'name' => $employee->user->name, 'position' => $employee->user->position],
            'items' => EmployeePayItem::query()->with('component')->where('employee_id', $employee->id)->orderByDesc('effective_from')->get()
                ->map(fn (EmployeePayItem $item) => $profiles->presentItem($item)),
            'loans' => $loans->presentMany(EmployeeLoan::query()->with('component')->where('employee_id', $employee->id)->orderByDesc('start_month')->get(), withSchedule: true),
            'automatic' => PayComponent::query()->active()->where('automatic', true)->ordered()->get()
                ->map(fn (PayComponent $component) => ['id' => $component->id, 'name' => $component->name, 'kind' => $component->kind->value, 'rule' => $profiles->describe($component)]),
            'recent' => PayrollEntry::query()->with('run')->where('employee_id', $employee->id)->latest('id')->limit(6)->get()
                ->map(fn (PayrollEntry $entry) => [...$payrolls->presentEntry($entry), 'period' => $entry->run->period, 'run_id' => $entry->payroll_run_id, 'finalized' => $entry->run->isFinalized()]),
            'components' => PayComponent::query()->active()->ordered()->where('kind', '!=', PayComponentKind::Loan->value)->get()
                ->map(fn (PayComponent $component) => ['value' => $component->id, 'label' => $component->name.' — '.$profiles->describe($component), 'method' => $component->method->value]),
            'loanTypes' => PayComponent::query()->active()->ordered()->where('kind', PayComponentKind::Loan->value)->get()
                ->map(fn (PayComponent $component) => ['value' => $component->id, 'label' => $component->name]),
            'canManage' => $request->user()->can('managePayOf', [PayrollRun::class, $employee]),
        ]);
    }

    public function storeItem(Request $request, Employee $employee, PayProfiles $profiles): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);

        $item = $profiles->saveItem($employee, null, $request->validate($profiles->itemRules()));
        $this->audit()->record(AuditEvent::PayItemSaved, "Added {$item->label}", $employee, ['component' => $item->component->code]);

        return back()->with('status', "{$item->component->name} added to the pay profile.");
    }

    public function updateItem(Request $request, Employee $employee, EmployeePayItem $payItem, PayProfiles $profiles): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);

        $profiles->saveItem($employee, $payItem, $request->validate($profiles->itemRules()));
        $this->audit()->record(AuditEvent::PayItemSaved, "Updated {$payItem->label}", $employee, ['component' => $payItem->component->code]);

        return back()->with('status', "{$payItem->component->name} updated.");
    }

    public function destroyItem(Employee $employee, EmployeePayItem $payItem): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);

        $this->audit()->record(AuditEvent::PayItemDeleted, "Removed {$payItem->label}", $employee, ['component' => $payItem->component->code]);
        $payItem->delete();

        return back()->with('status', "{$payItem->component->name} removed from the pay profile.");
    }

    public function storeLoan(Request $request, Employee $employee, PayProfiles $profiles, DeductionNotices $notices): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);

        $loan = $profiles->saveLoan($employee, null, $request->validate($profiles->loanRules()));
        $this->audit()->record(AuditEvent::LoanSaved, "Added {$loan->label}", $loan);
        $notices->assigned($loan);

        return back()->with('status', "{$loan->component->name} added.");
    }

    public function updateLoan(Request $request, Employee $employee, EmployeeLoan $loan, PayProfiles $profiles, DeductionNotices $notices): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);
        $data = $request->validate([...$profiles->loanRules(), 'change_reason' => ['nullable', 'string', 'max:500']]);

        $before = (float) $loan->monthly_amortization;
        $profiles->saveLoan($employee, $loan, $data);
        $this->audit()->recordChanges(AuditEvent::LoanSaved, "Updated {$loan->label}", $loan, ['reason' => $data['change_reason'] ?? null]);
        if ($before !== (float) $loan->monthly_amortization) {
            $notices->amountChanged($loan);
        }

        return back()->with('status', "{$loan->component->name} updated.");
    }

    public function stopLoan(Request $request, Employee $employee, EmployeeLoan $loan, PayProfiles $profiles, DeductionNotices $notices): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);
        $data = $request->validate(['stopped_on' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:500']]);

        $profiles->stopLoan($loan, $data['stopped_on']);
        $this->audit()->record(AuditEvent::LoanStopped, "Suspended {$loan->label}", $loan, ['reason' => $data['reason'] ?? null]);
        $notices->suspended($loan);

        return back()->with('status', "{$loan->component->name} stopped: no more deductions after {$data['stopped_on']}.");
    }

    public function resumeLoan(Employee $employee, EmployeeLoan $loan, PayProfiles $profiles, DeductionNotices $notices): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);

        $profiles->resumeLoan($loan);
        $this->audit()->record(AuditEvent::LoanResumed, "Resumed {$loan->label}", $loan);
        $notices->resumed($loan);

        return back()->with('status', "{$loan->component->name} resumed.");
    }

    public function cancelLoan(Request $request, Employee $employee, EmployeeLoan $loan, PayProfiles $profiles, DeductionNotices $notices): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $profiles->cancelLoan($loan, today()->toDateString());
        $this->audit()->record(AuditEvent::LoanCancelled, "Cancelled {$loan->label}: {$data['reason']}", $loan, ['reason' => $data['reason']]);
        $notices->cancelled($loan);

        return back()->with('status', "{$loan->component->name} cancelled: nothing more will be deducted.");
    }

    /** Marks one installment as actually paid (or, with `paid` false, clears it). */
    public function remitLine(Request $request, Employee $employee, EmployeeLoan $loan, PayrollLine $line, Loans $loans): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);
        abort_unless($line->employee_loan_id === $loan->id, 404);
        $data = $request->validate(['paid' => ['required', 'boolean'], 'remitted_on' => ['nullable', 'date', 'before_or_equal:today']]);

        if ($data['paid']) {
            $loans->markRemitted($line, $request->user(), $data['remitted_on'] ?? null);
        } else {
            $loans->unmarkRemitted($line);
        }
        $this->audit()->record(AuditEvent::LoanRemitted, ($data['paid'] ? 'Marked paid' : 'Cleared the paid mark of')." an installment of {$loan->label}", $loan, ['month' => $line->entry->run->month()->format('Y-m'), 'paid' => (bool) $data['paid']]);

        return back()->with('status', $data['paid'] ? 'Marked as paid.' : 'Paid mark cleared.');
    }

    public function destroyLoan(Employee $employee, EmployeeLoan $loan, PayProfiles $profiles): RedirectResponse
    {
        $this->authorize('managePayOf', [PayrollRun::class, $employee]);

        $this->audit()->record(AuditEvent::LoanDeleted, "Deleted {$loan->label}", $employee);
        $profiles->deleteLoan($loan);

        return back()->with('status', 'Loan deleted.');
    }
}
