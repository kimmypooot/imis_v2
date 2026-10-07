<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\Status;
use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Service\StoreServiceRequest;
use App\Http\Requests\Service\UpdateServiceRequest;
use App\Http\Requests\Service\UpdateServiceStatusRequest;
use App\Models\OrganizationalUnit;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Service::class);

        $services = Service::query()
            ->with(['organizationalUnit:id,name', 'category:id,name'])
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('short_name', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->value(), fn ($query, $status) => $query->where('status', $status))
            ->when(
                $request->integer('category_id') ?: null,
                fn ($query, $categoryId) => $query->where('category_id', $categoryId),
            )
            ->when(
                $request->integer('organizational_unit_id') ?: null,
                fn ($query, $unitId) => $query->where('organizational_unit_id', $unitId),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Services/Index', [
            'services' => $services,
            'filters' => $request->only(['search', 'status', 'category_id', 'organizational_unit_id']),
            ...$this->formOptions(),
        ]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $service = Service::create([
            ...$request->validated(),
            'slug' => Service::generateUniqueSlug($request->validated('name')),
        ]);

        $this->audit()->record(AuditEvent::ServiceCreated, "Created service {$service->name}", $service);

        return back()->with('status', "Service {$service->name} was added.");
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->validated());

        $this->audit()->recordChanges(AuditEvent::ServiceUpdated, "Updated service {$service->name}", $service);

        return back()->with('status', "Service {$service->name} was saved.");
    }

    public function updateStatus(UpdateServiceStatusRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->validated());

        $this->audit()->recordChanges(
            AuditEvent::ServiceStatusChanged,
            "{$this->activationVerb($service->status)} service {$service->name}",
            $service,
        );

        return back()->with('status', "Service {$service->name} was ".strtolower($this->activationVerb($service->status)).'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'statusOptions' => Status::options(),
            'visibilityOptions' => array_map(
                fn (Visibility $case) => ['value' => $case->value, 'label' => ucfirst($case->value)],
                Visibility::cases(),
            ),
            'categoryOptions' => ServiceCategory::query()->active()->ordered()->get(['id', 'name']),
            'organizationalUnitOptions' => OrganizationalUnit::query()->active()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
