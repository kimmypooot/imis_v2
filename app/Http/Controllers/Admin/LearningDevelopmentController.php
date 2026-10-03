<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\LdApprovalStatus;
use App\Enums\LdModality;
use App\Enums\LdType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Support\HR\LearningDevelopment\LdRecords;
use App\Support\HR\LearningDevelopment\LdReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-3b: HRD's Learning & Development overview (ldms's ld_manage.php) —
 * every employee with their trainings and hours for a year, and bulk add
 * (one training for many people, approved at once, duplicates skipped).
 */
class LearningDevelopmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PdsLearningDevelopment::class);

        $viewer = $request->user();
        $year = (int) $request->integer('year', (int) now()->year);

        // Approved and pending of the year; reports count approved only.
        $records = PdsLearningDevelopment::query()
            ->whereYear('date_from', $year)
            ->whereIn('approval_status', [LdApprovalStatus::Approved, LdApprovalStatus::Pending])
            ->get(['employee_id', 'hours', 'approval_status'])
            ->groupBy('employee_id');

        $employees = Employee::query()
            ->with('user.organizationalUnit')
            ->get()
            ->sortBy(fn (Employee $employee) => $employee->user->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $years = app(LdReports::class)->years($year);

        return Inertia::render('Admin/HR/LearningDevelopment/Index', [
            'year' => $year,
            'years' => $years,
            'employees' => $employees->map(function (Employee $employee) use ($records, $viewer) {
                $own = $records->get($employee->id, collect());
                $approved = $own->where('approval_status', LdApprovalStatus::Approved);

                return [
                    'id' => $employee->id,
                    'name' => $employee->user->name,
                    'position' => $employee->user->position,
                    'unit' => $employee->user->organizationalUnit?->short_name ?: $employee->user->organizationalUnit?->name,
                    'separated' => $employee->isSeparated(),
                    'trainings' => $approved->count(),
                    'hours' => (float) $approved->sum('hours'),
                    'pending' => $own->count() - $approved->count(),
                    'can_manage' => $viewer->can('manageLd', $employee),
                ];
            }),
            'pendingTotal' => $viewer->can('viewQueue', PdsLearningDevelopment::class)
                ? PdsLearningDevelopment::query()->where('approval_status', LdApprovalStatus::Pending)->count()
                : null,
            'canBulkAdd' => $viewer->can('createMany', PdsLearningDevelopment::class),
            'typeOptions' => LdType::options(),
            'modalityOptions' => LdModality::options(),
        ]);
    }

    public function storeMany(Request $request, LdRecords $records): RedirectResponse
    {
        $this->authorize('createMany', PdsLearningDevelopment::class);

        $validated = $request->validate($records->bulkRules(), [], [
            ...LdRecords::attributes(),
            'employee_ids' => 'employees',
        ]);

        $employees = Employee::query()->with('user')->whereKey($validated['employee_ids'])->get();

        // Every id must be a record this person may manage (never their own).
        if ($employees->count() !== count($validated['employee_ids'])
            || $employees->contains(fn (Employee $employee) => $request->user()->cannot('manageLd', $employee))) {
            throw ValidationException::withMessages(['employee_ids' => 'Pick employees from the list (you cannot add to your own log).']);
        }

        ['created' => $created, 'skipped' => $skipped] = $records->createMany($employees, $validated, $request->user());

        foreach ($created as $record) {
            $name = $employees->firstWhere('id', $record->employee_id)->user->name;
            $this->audit()->record(AuditEvent::LdRecordCreated, "Added the training \"{$record->title}\" to the L&D log of {$name}", $record, ['on_behalf' => true, 'bulk' => true]);
        }

        $message = $created->isEmpty()
            ? 'Everyone selected already has this training. No records were added.'
            : 'Added the training to '.$created->count().' '.($created->count() === 1 ? 'employee' : 'employees').'.';

        if ($created->isNotEmpty() && $skipped->isNotEmpty()) {
            $message .= ' Skipped '.$skipped->count().' who already had it: '.$skipped->map(fn (Employee $employee) => $employee->user->name)->join(', ').'.';
        }

        return back()->with($created->isEmpty() ? 'error' : 'status', $message);
    }
}
