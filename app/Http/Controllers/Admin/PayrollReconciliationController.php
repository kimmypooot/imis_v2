<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Support\HR\Payroll\Payrolls;
use App\Support\HR\Payroll\PayrollWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * HR-8: sets the accounting office's payroll workbook beside a payroll run
 * in IMIS, person by person (see PayrollWorkbook::reconcile). The upload is
 * read and discarded; the result is kept in the session for review. Nothing
 * is saved to the payroll.
 */
class PayrollReconciliationController extends Controller
{
    public function show(PayrollRun $run, Payrolls $payrolls): Response
    {
        $this->authorize('view', $run);

        return Inertia::render('Admin/HR/Payroll/Reconciliation', [
            'run' => $payrolls->presentRun($run),
            'result' => session($this->key($run)),
        ]);
    }

    public function store(Request $request, PayrollRun $run, PayrollWorkbook $workbook): RedirectResponse
    {
        $this->authorize('view', $run);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:30720']], [], ['file' => 'payroll workbook']);

        set_time_limit(300);
        ini_set('memory_limit', '1024M');

        try {
            $employees = $workbook->employees($workbook->read($request->file('file')->getRealPath()));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['file' => 'This workbook could not be read. Save it again from Excel as .xlsx and retry.']);
        }
        if ($employees->isEmpty()) {
            return back()->withErrors(['file' => 'No employees were found on the "Final Payroll" sheet.']);
        }

        session([$this->key($run) => [...$workbook->reconcile($employees, $run), 'file' => $request->file('file')->getClientOriginalName(), 'at' => now()->toIso8601String()]]);

        return to_route('admin.hr.payroll.reconciliation', $run);
    }

    public function destroy(PayrollRun $run): RedirectResponse
    {
        $this->authorize('view', $run);
        session()->forget($this->key($run));

        return to_route('admin.hr.payroll.reconciliation', $run);
    }

    private function key(PayrollRun $run): string
    {
        return "payroll_reconciliation.{$run->id}";
    }
}
