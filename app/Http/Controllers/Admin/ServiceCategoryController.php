<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceCategory\StoreServiceCategoryRequest;
use App\Http\Requests\ServiceCategory\UpdateServiceCategoryRequest;
use App\Http\Requests\ServiceCategory\UpdateServiceCategoryStatusRequest;
use App\Models\ServiceCategory;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The categories services are grouped under in the portal directory.
 * Deactivation only, no delete: a deactivated category drops out of the
 * pickers and the portal filter, but services keep their link to it.
 */
class ServiceCategoryController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ServiceCategory::class);

        return Inertia::render('Admin/ServiceCategories/Index', [
            'categories' => ServiceCategory::query()
                ->withCount('services')
                ->ordered()
                ->get(),
            'statusOptions' => Status::options(),
        ]);
    }

    public function store(StoreServiceCategoryRequest $request): RedirectResponse
    {
        $category = ServiceCategory::create([
            ...$request->validated(),
            'slug' => ServiceCategory::generateUniqueSlug($request->validated('name')),
        ]);

        $this->audit()->record(AuditEvent::ServiceCategoryCreated, "Created service category {$category->name}", $category);

        return back()->with('status', "Category {$category->name} was added.");
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $serviceCategory): RedirectResponse
    {
        $serviceCategory->update($request->validated());

        $this->audit()->recordChanges(AuditEvent::ServiceCategoryUpdated, "Updated service category {$serviceCategory->name}", $serviceCategory);

        return back()->with('status', "Category {$serviceCategory->name} was saved.");
    }

    public function updateStatus(UpdateServiceCategoryStatusRequest $request, ServiceCategory $serviceCategory): RedirectResponse
    {
        $serviceCategory->update($request->validated());

        $this->audit()->recordChanges(
            AuditEvent::ServiceCategoryStatusChanged,
            "{$this->activationVerb($serviceCategory->status)} service category {$serviceCategory->name}",
            $serviceCategory,
        );

        return back()->with('status', "Category {$serviceCategory->name} was ".strtolower($this->activationVerb($serviceCategory->status)).'.');
    }
}
