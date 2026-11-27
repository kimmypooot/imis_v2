<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\OrganizationalUnit;
use App\Support\HR\Leave\CalendarAudience;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Leave\LeaveCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave on a month grid, for HR: who is out on which day, by office and
 * type of leave. Only the few in CalendarAudience (the head of the Human
 * Resource Division, a Director IV or III, a Super Administrator) see every
 * office; everyone else here sees their own division's. Like the
 * applications list, opening one application is what lands on the access
 * trail.
 */
class LeaveCalendarController extends Controller
{
    public function index(Request $request, LeaveCalendar $calendar, CalendarAudience $audience): Response
    {
        $this->authorize('viewAny', LeaveApplication::class);

        $user = $request->user();
        $everyone = $audience->seesEveryone($user);

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'office' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')],
            'type' => ['nullable', 'integer', Rule::exists('leave_types', 'id')],
            'pending' => ['nullable', 'boolean'],
        ]);
        $month = isset($filters['month']) ? Carbon::createFromFormat('Y-m', $filters['month'])->startOfMonth() : today()->startOfMonth();

        // Without the wide view, the office is always their own division, whatever was asked for.
        $own = $user->organizational_unit_id;
        abort_unless($everyone || $own, 403, 'You have no division, so there is no leave calendar to show.');

        $filters = [
            'office' => $everyone ? (isset($filters['office']) ? (int) $filters['office'] : null) : $own,
            'type' => isset($filters['type']) ? (int) $filters['type'] : null,
            'pending' => (bool) ($filters['pending'] ?? false),
        ];

        return Inertia::render('Admin/HR/Leave/Calendar', [
            'month' => $month->format('Y-m'),
            'filters' => $filters,
            'seesEveryone' => $everyone,
            'division' => $user->organizationalUnit?->name,
            'officeOptions' => OrganizationalUnit::query()->active()->workingOffices()->inOrder()->get()
                ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]),
            'typeOptions' => LeaveType::query()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (LeaveType $type) => ['value' => $type->id, 'label' => $type->name, 'code' => $type->code]),
            'leave' => $calendar->leave($month, $filters),
            'holidays' => $calendar->holidays($month, $month->copy()->endOfMonth()),
        ]);
    }

    /**
     * One application's details for the calendar's modal. Same reach as the
     * calendar itself: every office for those in CalendarAudience (HR head,
     * Director IV/III, Super Administrator), otherwise the viewer's own
     * division. Opening it lands on the access trail.
     */
    public function show(Request $request, LeaveApplication $application, LeaveApplications $applications, CalendarAudience $audience): JsonResponse
    {
        $this->authorize('viewAny', LeaveApplication::class);

        $user = $request->user();
        $application->loadMissing('employee.user');
        abort_unless(
            $audience->seesEveryone($user)
                || ($user->organizational_unit_id && $application->employee->user->organizational_unit_id === $user->organizational_unit_id),
            403,
        );

        if ($application->employee->user_id !== $user->id) {
            $this->audit()->record(AuditEvent::LeaveApplicationViewed, "Viewed {$application->label}", $application);
        }

        return response()->json([
            'application' => $applications->present($application),
            'steps' => $applications->presentSteps($application),
            // The form follows the application page's own permission, which not everyone here has.
            'can_print' => $user->can('view', $application),
        ]);
    }
}
