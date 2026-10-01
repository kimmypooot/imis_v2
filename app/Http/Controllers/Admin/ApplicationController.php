<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\Status;
use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Application\StoreApplicationRequest;
use App\Http\Requests\Application\UpdateApplicationMaintenanceRequest;
use App\Http\Requests\Application\UpdateApplicationRequest;
use App\Http\Requests\Application\UpdateApplicationStatusRequest;
use App\Models\Application;
use App\Models\Committee;
use App\Models\OrganizationalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    /**
     * Listing fields are synced separately (pivot), never mass-assigned.
     */
    private const LISTING_FIELDS = ['listed_unit_ids', 'listed_committee_ids'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Application::class);

        $applications = Application::query()
            ->with('organizationalUnit:id,name')
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('short_name', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->value(), fn ($query, $status) => $query->where('status', $status))
            ->when(
                $request->integer('organizational_unit_id') ?: null,
                fn ($query, $unitId) => $query->where('organizational_unit_id', $unitId),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Applications/Index', [
            'applications' => $applications,
            'filters' => $request->only(['search', 'status', 'organizational_unit_id']),
            'statusOptions' => Status::options(),
            'organizationalUnitOptions' => OrganizationalUnit::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Application::class);

        return Inertia::render('Admin/Applications/Create', $this->formOptions());
    }

    public function store(StoreApplicationRequest $request): RedirectResponse
    {
        $application = Application::create($request->safe()->except(self::LISTING_FIELDS));
        $this->syncListings($request, $application);

        $this->audit()->record(AuditEvent::ApplicationCreated, "Created system {$application->name}", $application);

        return to_route('admin.applications.index');
    }

    public function edit(Application $application): Response
    {
        $this->authorize('update', $application);

        return Inertia::render('Admin/Applications/Edit', [
            'application' => [
                ...$application->toArray(),
                'listed_unit_ids' => $application->listedUnits()->pluck('organizational_units.id'),
                'listed_committee_ids' => $application->listedCommittees()->pluck('committees.id'),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateApplicationRequest $request, Application $application): RedirectResponse
    {
        $application->update($request->safe()->except(self::LISTING_FIELDS));

        $this->audit()->recordChanges(AuditEvent::ApplicationUpdated, "Updated system {$application->name}", $application);
        $this->syncListings($request, $application);

        return to_route('admin.applications.index');
    }

    public function updateStatus(UpdateApplicationStatusRequest $request, Application $application): RedirectResponse
    {
        $application->update($request->validated());

        $this->audit()->recordChanges(
            AuditEvent::ApplicationStatusChanged,
            "{$this->activationVerb($application->status)} system {$application->name}",
            $application,
        );

        return to_route('admin.applications.index');
    }

    /**
     * Starts maintenance, updates its message while it's running, or ends
     * it. The start time is kept across message edits.
     */
    public function updateMaintenance(UpdateApplicationMaintenanceRequest $request, Application $application): RedirectResponse
    {
        $wasUnderMaintenance = $application->under_maintenance;
        $message = $request->validated('maintenance_message') ?: null;

        if (! $request->boolean('under_maintenance')) {
            if ($wasUnderMaintenance) {
                $application->endMaintenance();
                $this->audit()->record(AuditEvent::ApplicationMaintenanceEnded, "Ended maintenance of system {$application->name}", $application);
            }

            return back();
        }

        $application->startMaintenance($message);

        if (! $wasUnderMaintenance) {
            $this->audit()->record(
                AuditEvent::ApplicationMaintenanceStarted,
                "Put system {$application->name} under maintenance",
                $application,
                ['message' => $message],
            );
        } else {
            $this->audit()->recordChanges(
                AuditEvent::ApplicationMaintenanceUpdated,
                "Updated the maintenance notice of system {$application->name}",
                $application,
            );
        }

        return back();
    }

    /**
     * Where else the system appears in the dashboard directory. Only
     * touched when the form sent the field, so an older client can't wipe
     * listings by omission. Changes are audited as names added/removed.
     */
    private function syncListings(Request $request, Application $application): void
    {
        $relations = [
            'listed_unit_ids' => ['listedUnits', OrganizationalUnit::class],
            'listed_committee_ids' => ['listedCommittees', Committee::class],
        ];

        foreach ($relations as $field => [$relation, $model]) {
            if (! $request->has($field)) {
                continue;
            }

            $this->recordPivotChange(
                AuditEvent::ApplicationListingsChanged,
                "Changed where system {$application->name} is listed",
                $application,
                $model,
                $application->{$relation}()->sync($request->input($field, [])),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'committeeOptions' => Committee::query()->active()->orderBy('name')->get(['id', 'name']),
            'statusOptions' => Status::options(),
            'visibilityOptions' => array_map(
                fn (Visibility $case) => ['value' => $case->value, 'label' => ucfirst($case->value)],
                Visibility::cases(),
            ),
            'organizationalUnitOptions' => OrganizationalUnit::query()->active()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
