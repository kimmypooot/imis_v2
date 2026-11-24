<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\DtrAdjustment;
use App\Support\HR\Dtr\DtrAdjustments;
use App\Support\HR\Dtr\DtrDownload;
use App\Support\HR\Dtr\DtrSheets;
use App\Support\HR\Dtr\DtrSignatures;
use App\Support\HR\Dtr\DtrWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DTR self-service: your own monthly Daily Time Record, read-only, with no
 * permission needed. Corrections come from HRD.
 */
class MyDtrController extends Controller
{
    public function print(Request $request, DtrSheets $sheets): Response
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404);

        $range = $request->validate(DtrSheets::rangeRules($request->query('from')));

        return Inertia::render('HR/DtrPrint', [
            ...$sheets->header($employee),
            'sheet' => $sheets->forRange($employee, Carbon::parse($range['from']), Carbon::parse($range['to'])),
            'generated_at' => now()->format('Y-m-d H:i'),
        ]);
    }

    /** Your own sheet as an Excel workbook: the printed form's layout, with the validation code but not the signature image. */
    public function export(Request $request, DtrSheets $sheets, DtrWorkbook $workbook): HttpResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404);

        $range = $request->validate(DtrSheets::rangeRules($request->query('from')));
        $sheet = $sheets->forRange($employee, Carbon::parse($range['from']), Carbon::parse($range['to']));

        return DtrDownload::xlsx($workbook->build([['employee' => $employee->user->name, 'sheet' => $sheet]], now()->format('n/j/Y g:i:s A')), "CS Form 48 - {$employee->user->name} - {$sheet['period']}");
    }

    /**
     * Your own in and out times for days on your sheet, once you certify
     * they are true and correct. HRD's adjustments stay HRD's; the raw
     * punches are never touched. Every entry is audited with what it
     * replaced, and a sheet you signed shows as changed until you sign again.
     */
    public function adjust(Request $request, DtrAdjustments $adjustments): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404);

        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:62'],
            'entries.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'entries.*.time_in' => ['nullable', 'string', 'max:5'],
            'entries.*.time_out' => ['nullable', 'string', 'max:5'],
            'entries.*.remark' => ['nullable', 'string', 'max:255'],
            'certify' => ['accepted'],
        ], ['certify.accepted' => 'Confirm that what you entered is true and correct.']);

        $before = DtrAdjustment::query()->where('employee_id', $employee->id)->whereIn('date', array_column($data['entries'], 'date'))->get()->keyBy(fn (DtrAdjustment $row) => $row->date->toDateString());
        $result = $adjustments->saveByEmployee($employee, $data['entries'], $request->user());

        $this->audit()->record(AuditEvent::DtrAdjustmentSaved, 'Entered their own DTR times and certified them as true and correct', $employee, [
            'by_employee' => true,
            'entries' => collect($data['entries'])->map(fn (array $entry) => [
                'date' => $entry['date'],
                'time_in' => $entry['time_in'] ?? null,
                'time_out' => $entry['time_out'] ?? null,
                'remark' => $entry['remark'] ?? null,
                'replaced' => $before->has($entry['date']) ? ['time_in' => $before[$entry['date']]->time_in, 'time_out' => $before[$entry['date']]->time_out, 'remark' => $before[$entry['date']]->remark] : null,
            ])->values()->all(),
        ]);

        $count = count($result['saved']);

        return back()->with('status', trim(
            ($count ? "Saved {$count} ".($count === 1 ? 'day' : 'days').' as certified true and correct. ' : '')
            .($result['removed'] ? "Took back {$result['removed']} of your entries. " : '')
            .'If you had signed a sheet, sign it again.'
        ));
    }

    /**
     * Signs a finished sheet with your saved e-signature. Re-checks your
     * password, like the other e-signatures.
     */
    public function sign(Request $request, DtrSignatures $signatures): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404);

        $data = $request->validate([
            ...DtrSheets::rangeRules($request->input('from')),
            'password' => ['required', 'current_password'],
        ], ['password.current_password' => 'That password is incorrect.']);

        $signature = $signatures->sign($employee, $request->user(), Carbon::parse($data['from']), Carbon::parse($data['to']));

        $this->audit()->record(AuditEvent::DtrSigned, 'Signed their DTR for '.$signature->period_from->format('M j').' to '.$signature->period_to->format('M j, Y'), $employee, [
            'from' => $signature->period_from->toDateString(),
            'to' => $signature->period_to->toDateString(),
            'code' => $signature->code,
        ]);

        return back()->with('status', 'Your DTR is signed. The printed form carries your signature and a code to validate it.');
    }

    public function show(Request $request, DtrSheets $sheets): Response
    {
        $month = DtrSheets::parseMonth($request->query('month'));
        $employee = $request->user()->employee;

        return Inertia::render('Portal/MyDtr', $employee
            ? [...$sheets->forMonth($employee, $month), 'earliestEntry' => DtrAdjustments::earliestEntry()->toDateString(), 'hasRecord' => true, 'hasSignature' => $request->user()->signature_path !== null]
            : ['employee' => null, 'month' => $month->format('Y-m'), 'label' => $month->format('F Y'), 'sheets' => [], 'hasRecord' => false]);
    }
}
