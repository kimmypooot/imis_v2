<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\LeaveApplicationStatus;
use App\Models\LeaveApplication;
use App\Support\HR\Leave\CalendarAudience;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Leave\LeaveCalendar;
use App\Support\HR\Leave\LeaveCredits;
use App\Support\HR\Leave\LeaveDates;
use Illuminate\Http\JsonResponse;
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
    public function calendar(Request $request, LeaveCalendar $calendar, CalendarAudience $audience): Response
    {
        $employee = $request->user()->employee;

        try {
            $month = Carbon::createFromFormat('Y-m', (string) $request->query('month', today()->format('Y-m')))->startOfMonth();
        } catch (\Throwable) {
            $month = today()->startOfMonth();
        }
        $end = $month->copy()->endOfMonth();
        $everyone = $audience->seesEveryone($request->user());

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
                    'dates' => $application->hasChosenDates() ? $application->dates : null,
                    'dates_label' => LeaveDates::describe($application),
                    'status' => $application->status->value,
                    'is_half_day' => $application->is_half_day,
                    'half_day_period' => $application->half_day_period,
                ]) : [],
            // Co-workers in your division who've filed leave (no type of leave); the
            // few who see every office (CalendarAudience) get everyone's.
            'colleagues' => $employee ? $calendar->colleagues($employee, $month, $everyone) : [],
            'seesEveryone' => $everyone,
            'division' => $request->user()->organizationalUnit?->name,
            'holidays' => $calendar->holidays($month, $end),
        ]);
    }

    /**
     * One application for the calendar's modal. HR head, Director IV/III and
     * Super Administrator (CalendarAudience) get the full details; a
     * co-worker in the same division gets only who, what kind of leave, the
     * dates and the status, for leave the calendar shows (approved, under
     * review or returned). Reasons, remarks and the approval steps stay
     * private (RA 10173). Opening someone else's lands on the access trail.
     */
    public function calendarDetails(Request $request, LeaveApplication $application, LeaveApplications $applications, CalendarAudience $audience): JsonResponse
    {
        $user = $request->user();
        $application->loadMissing('employee.user');
        $own = $application->employee->user_id === $user->id;
        $everyone = $audience->seesEveryone($user);

        abort_unless($own || $everyone || (
            $user->organizational_unit_id
            && $application->employee->user->organizational_unit_id === $user->organizational_unit_id
            && in_array($application->status, [LeaveApplicationStatus::Approved, LeaveApplicationStatus::ForReview, LeaveApplicationStatus::Returned], true)
        ), 403);

        if (! $own) {
            $this->audit()->record(AuditEvent::LeaveApplicationViewed, "Viewed {$application->label}", $application);
        }

        $full = $own || $everyone;
        $details = $applications->present($application);

        return response()->json([
            'application' => $full ? $details : [
                ...collect($details)->only(['id', 'name', 'office', 'type_code', 'type_name', 'date_from', 'date_to', 'dates', 'dates_label', 'is_half_day', 'half_day_period', 'days', 'status', 'status_label'])->all(),
                'limited' => true,
            ],
            'steps' => $full ? $applications->presentSteps($application) : [],
            // The form follows the application page's own permission, which not everyone here has.
            'can_print' => $full && $user->can('view', $application),
        ]);
    }
}
