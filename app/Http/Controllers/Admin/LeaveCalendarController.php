<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\OrganizationalUnit;
use App\Support\HR\Leave\LeaveCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everyone's leave on a month grid, for HR: who is out on which day, by
 * office and type of leave. Like the applications list, opening one
 * application is what lands on the access trail.
 */
class LeaveCalendarController extends Controller
{
    public function index(Request $request, LeaveCalendar $calendar): Response
    {
        $this->authorize('viewAny', LeaveApplication::class);

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'office' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')],
            'type' => ['nullable', 'integer', Rule::exists('leave_types', 'id')],
            'pending' => ['nullable', 'boolean'],
        ]);
        $month = isset($filters['month']) ? Carbon::createFromFormat('Y-m', $filters['month'])->startOfMonth() : today()->startOfMonth();
        $filters = [
            'office' => isset($filters['office']) ? (int) $filters['office'] : null,
            'type' => isset($filters['type']) ? (int) $filters['type'] : null,
            'pending' => (bool) ($filters['pending'] ?? false),
        ];

        return Inertia::render('Admin/HR/Leave/Calendar', [
            'month' => $month->format('Y-m'),
            'filters' => $filters,
            'officeOptions' => OrganizationalUnit::query()->active()->orderBy('name')->get()
                ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]),
            'typeOptions' => LeaveType::query()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (LeaveType $type) => ['value' => $type->id, 'label' => $type->name, 'code' => $type->code]),
            'leave' => $calendar->leave($month, $filters),
            'holidays' => $calendar->holidays($month, $month->copy()->endOfMonth()),
        ]);
    }
}
