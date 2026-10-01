<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\Status;
use App\Enums\UnitType;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationalUnit\StoreOrganizationalUnitRequest;
use App\Http\Requests\OrganizationalUnit\UpdateOrganizationalUnitRequest;
use App\Http\Requests\OrganizationalUnit\UpdateOrganizationalUnitStatusRequest;
use App\Models\OrganizationalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationalUnitController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationalUnit::class);

        $units = OrganizationalUnit::query()
            ->with('parent:id,name,code')
            ->withCount('children')
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->value(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('unit_type')->value(), fn ($query, $type) => $query->where('unit_type', $type))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/OrganizationalUnits/Index', [
            'organizationalUnits' => $units,
            'filters' => $request->only(['search', 'status', 'unit_type']),
            'statusOptions' => Status::options(),
            'unitTypeOptions' => UnitType::options(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', OrganizationalUnit::class);

        return Inertia::render('Admin/OrganizationalUnits/Create', [
            'parentOptions' => $this->parentOptions(),
            'statusOptions' => Status::options(),
            'unitTypeOptions' => UnitType::options(),
        ]);
    }

    public function store(StoreOrganizationalUnitRequest $request): RedirectResponse
    {
        $unit = OrganizationalUnit::create([
            ...$request->validated(),
            'slug' => OrganizationalUnit::generateUniqueSlug($request->validated('name')),
        ]);

        $this->audit()->record(AuditEvent::OrganizationalUnitCreated, "Created organizational unit {$unit->name}", $unit);

        return to_route('admin.organizational-units.index');
    }

    public function edit(OrganizationalUnit $organizationalUnit): Response
    {
        $this->authorize('update', $organizationalUnit);

        $organizationalUnit->load('parent:id,name,code');

        return Inertia::render('Admin/OrganizationalUnits/Edit', [
            'organizationalUnit' => $organizationalUnit,
            'ancestors' => $organizationalUnit->ancestors()->reverse()->values()
                ->map(fn (OrganizationalUnit $ancestor) => ['id' => $ancestor->id, 'name' => $ancestor->name]),
            'children' => $organizationalUnit->children()->orderBy('sort_order')->get(['id', 'name', 'code', 'status']),
            'parentOptions' => $this->parentOptions($organizationalUnit),
            'statusOptions' => Status::options(),
            'unitTypeOptions' => UnitType::options(),
        ]);
    }

    public function update(UpdateOrganizationalUnitRequest $request, OrganizationalUnit $organizationalUnit): RedirectResponse
    {
        $organizationalUnit->update($request->validated());

        $this->audit()->recordChanges(
            AuditEvent::OrganizationalUnitUpdated,
            "Updated organizational unit {$organizationalUnit->name}",
            $organizationalUnit,
        );

        return to_route('admin.organizational-units.index');
    }

    public function updateStatus(
        UpdateOrganizationalUnitStatusRequest $request,
        OrganizationalUnit $organizationalUnit,
    ): RedirectResponse {
        $organizationalUnit->update($request->validated());

        $this->audit()->recordChanges(
            AuditEvent::OrganizationalUnitStatusChanged,
            "{$this->activationVerb($organizationalUnit->status)} organizational unit {$organizationalUnit->name}",
            $organizationalUnit,
        );

        return to_route('admin.organizational-units.index');
    }

    /**
     * Eligible parents for the given unit — every unit except itself and
     * its own descendants (selecting either would create a cycle; the
     * update request re-validates this independently server-side too).
     *
     * @return array<int, array{id: int, name: string, code: string|null}>
     */
    private function parentOptions(?OrganizationalUnit $excluding = null): array
    {
        $excludedIds = $excluding
            ? $excluding->descendants()->push($excluding)->pluck('id')->all()
            : [];

        return OrganizationalUnit::query()
            ->whereNotIn('id', $excludedIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->toArray();
    }
}
