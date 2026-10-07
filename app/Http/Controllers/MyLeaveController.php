<?php

namespace App\Http\Controllers;

use App\Enums\LeaveApplicationStatus;
use App\Models\LeaveApplication;
use App\Support\HR\Leave\LeaveCalendar;
use App\Support\HR\Leave\LeaveCredits;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6 self-service: your own leave credits and leave card, read-only
 * (legacy leave/leave-card.php), and your leave calendar (HR-6c). Filing
 * is MyLeaveApplicationController.
 */
class MyLeaveController extends Controller
{
    public function show(Request $request, LeaveCredits $credits): Response
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return Inertia::render('Portal/MyLeave', ['hasRecord' => false]);
        }

        $year = $request->integer('year', (int) today()->year);
        $year = $year >= 1990 && $year <= 2100 ? $year : (int) today()->year;

        return Inertia::render('Portal/MyLeave', [
            'hasRecord' => true,
            ...$credits->cardPage($employee, $year),
        ]);
    }

    /**
     * HR-6c: your leave and the holidays in a month (legacy leave/calendar.php),
     * plus who else in your division has filed leave.
     */
    public function calendar(Request $request, LeaveCalendar $calendar): Response
    {
        $employee = $request->user()->employee;

        try {
            $month = Carbon::createFromFormat('Y-m', (string) $request->query('month', today()->format('Y-m')))->startOfMonth();
        } catch (\Throwable) {
            $month = today()->startOfMonth();
        }
        $end = $month->copy()->endOfMonth();

        return Inertia::render('Portal/LeaveCalendar', [
            'month' => $month->format('Y-m'),
            'leave' => $employee ? LeaveApplication::query()->where('employee_id', $employee->id)
                ->whereIn('status', [LeaveApplicationStatus::ForReview, LeaveApplicationStatus::Returned, LeaveApplicationStatus::Approved])
                ->whereDate('date_from', '<=', $end)->whereDate('date_to', '>=', $month)
                ->with('leaveType')->get()
                ->map(fn (LeaveApplication $application) => [
                    'id' => $application->id,
                    'code' => $application->leaveType->code,
                    'name' => $application->leaveType->name,
                    'date_from' => $application->date_from->toDateString(),
                    'date_to' => $application->date_to->toDateString(),
                    'status' => $application->status->value,
                    'is_half_day' => $application->is_half_day,
                    'half_day_period' => $application->half_day_period,
                ]) : [],
            // Co-workers in your division who've filed leave (no type of leave).
            'colleagues' => $employee ? $calendar->colleagues($employee, $month) : [],
            'division' => $request->user()->organizationalUnit?->name,
            'holidays' => $calendar->holidays($month, $end),
        ]);
    }
}
