<?php

namespace App\Http\Controllers;

use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Support\HR\Payroll\PayrollAccounting;
use App\Support\HR\Payroll\Payrolls;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-8: Accounting's queue — the payrolls HRD submitted for review and
 * withholding tax, oldest month first, and the last few sent back. Open to
 * the Accountant III on the Plantilla and anyone with `payroll.tax`.
 */
class PayrollTaxReviewController extends Controller
{
    public function index(Request $request, PayrollAccounting $accounting, Payrolls $payrolls): Response
    {
        $this->authorize('enterTax', PayrollRun::class);

        return Inertia::render('Portal/PayrollTaxReview', [
            'waiting' => $accounting->waiting()->map(fn (PayrollRun $run) => $payrolls->presentRun($run))->values(),
            'returned' => PayrollRun::query()->with('returner')->whereNotNull('returned_at')
                ->whereIn('status', [PayrollRunStatus::Returned, PayrollRunStatus::Finalized])
                ->latest('returned_at')->limit(6)->get()
                ->map(fn (PayrollRun $run) => $payrolls->presentRun($run))->values(),
        ]);
    }
}
