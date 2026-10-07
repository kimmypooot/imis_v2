<?php

namespace App\Http\Controllers;

use App\Support\Privacy\RecordAccessLog;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-2, "Who accessed my record": everyone sees who else opened or
 * printed their own HR records, and when (RecordAccessLog). No permission
 * needed — it is only ever the signed-in person's own record. Without an
 * employee record there is nothing to list.
 */
class MyRecordAccessController extends Controller
{
    public function index(Request $request): Response
    {
        $employee = $request->user()->employee;
        $year = $request->integer('year') ?: null;
        $log = $employee ? new RecordAccessLog($employee) : null;

        return Inertia::render('Portal/MyRecordAccess', [
            'entries' => $log?->paginate($year),
            'years' => $log?->years() ?? [],
            'year' => $year,
            'keptMonths' => (int) Settings::get('retention_personal_data_access_months'),
        ]);
    }
}
