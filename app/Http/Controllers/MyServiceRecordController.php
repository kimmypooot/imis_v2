<?php

namespace App\Http\Controllers;

use App\Support\HR\ServiceRecords;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-5 self-service: your own Service Record, read-only, with no
 * permission needed. Your printout is marked as a copy for reference; a
 * certified copy comes from HR.
 */
class MyServiceRecordController extends Controller
{
    public function show(Request $request, ServiceRecords $records): Response
    {
        $employee = $request->user()->employee;

        return Inertia::render('Portal/MyServiceRecord', $employee
            ? [...$records->build($employee), 'hasRecord' => true]
            : ['person' => null, 'lines' => [], 'hasRecord' => false]);
    }

    public function print(Request $request, ServiceRecords $records): Response
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404);

        return Inertia::render('HR/ServiceRecordPrint', [
            ...$records->build($employee),
            'certification' => $records->certification(),
            'certified' => false,
        ]);
    }
}
