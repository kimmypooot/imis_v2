<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LeaveApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\LeaveApplication;
use App\Support\HR\Leave\LeaveApplications;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6b: every leave application, for HR (legacy hrd/applications.php) —
 * by year and status, each linking to its page.
 */
class LeaveApplicationController extends Controller
{
    public function index(Request $request, LeaveApplications $applications): Response
    {
        $this->authorize('viewAny', LeaveApplication::class);

        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'between:1990,2100'],
            'status' => ['nullable', Rule::enum(LeaveApplicationStatus::class)],
        ]);
        $year = (int) ($filters['year'] ?? today()->year);

        return Inertia::render('Admin/HR/Leave/Applications', [
            'filters' => ['year' => $year, 'status' => $filters['status'] ?? null],
            'statusOptions' => LeaveApplicationStatus::options(),
            'applications' => LeaveApplication::query()
                ->whereYear('date_from', $year)
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->with(['leaveType', 'employee.user.organizationalUnit'])
                ->latest('date_from')->latest('id')->get()
                ->map(fn (LeaveApplication $application) => $applications->present($application)),
        ]);
    }
}
