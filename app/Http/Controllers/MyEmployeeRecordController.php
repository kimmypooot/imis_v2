<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmployeeResource;
use App\Support\HR\EmployeeAssignments;
use App\Support\HR\Plantilla\Plantilla;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-1 self-service: every signed-in person can read their own employee
 * record, with no permission needed. Reading your own record isn't
 * audited. Changes go through HR.
 */
class MyEmployeeRecordController extends Controller
{
    public function show(Request $request, EmployeeAssignments $assignments, Plantilla $plantilla): Response
    {
        $employee = $request->user()->employee()->with('user.organizationalUnit')->first();

        return Inertia::render('Portal/MyEmployeeRecord', [
            'employee' => $employee ? EmployeeResource::make($employee) : null,
            // HR-1b: your own movements between offices.
            'assignments' => $employee ? $assignments->timeline($employee, $request->user()) : null,
            // HR-4: the plantilla items you've held.
            'plantilla' => $employee ? $plantilla->historyOf($employee) : null,
        ]);
    }
}
