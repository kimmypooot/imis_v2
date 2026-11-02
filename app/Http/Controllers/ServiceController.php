<?php

namespace App\Http\Controllers;

use App\Http\Resources\ServiceResource;
use App\Models\OrganizationalUnit;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Settings::get('portal_services_enabled'), 404);

        $services = Service::query()
            ->visibleTo($request->user())
            ->with(['organizationalUnit:id,name', 'category:id,name'])
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when(
                $request->integer('category_id') ?: null,
                fn ($query, $categoryId) => $query->where('category_id', $categoryId),
            )
            ->when(
                $request->integer('organizational_unit_id') ?: null,
                fn ($query, $unitId) => $query->where('organizational_unit_id', $unitId),
            )
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Portal/Services/Index', [
            'services' => ServiceResource::collection($services),
            'filters' => $request->only(['search', 'category_id', 'organizational_unit_id']),
            'categoryOptions' => ServiceCategory::query()->active()->ordered()->get(['id', 'name']),
            'organizationalUnitOptions' => OrganizationalUnit::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Service $service): Response
    {
        abort_unless(Settings::get('portal_services_enabled') && $service->isVisibleTo($request->user()), 404);

        $service->load(['organizationalUnit:id,name', 'category:id,name']);

        return Inertia::render('Portal/Services/Show', [
            'service' => new ServiceResource($service),
        ]);
    }
}
