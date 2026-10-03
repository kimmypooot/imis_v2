<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\UnitType;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Committee;
use App\Models\OrganizationalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The legacy "Manage Dashboard" page: which systems appear under each
 * division card of the dashboard directory. Categories and divisions come
 * from the offices and committees themselves (edited on their own pages);
 * what is managed here is the placement of systems — the
 * application_listings pivot, seen from the division's side. A system
 * always appears under the office that manages it; that link is changed on
 * the system itself (Manage Systems), so it shows here as fixed.
 */
class DashboardLayoutController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('update', new Application);

        $units = OrganizationalUnit::query()
            ->with('listedApplications:id')
            ->withCount('applications')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $committees = Committee::query()->with('listedApplications:id')->orderBy('name')->get();
        $owned = Application::query()->whereNotNull('organizational_unit_id')->get(['id', 'organizational_unit_id'])->groupBy('organizational_unit_id');

        $categories = collect(UnitType::cases())
            ->map(fn (UnitType $type) => [
                'key' => $type->value,
                'label' => $type->pluralLabel(),
                'divisions' => $units->where('unit_type', $type)->map(fn (OrganizationalUnit $unit) => [
                    'key' => "unit-{$unit->id}",
                    'type' => 'unit',
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'short_name' => $unit->short_name,
                    'status' => $unit->status->value,
                    'owned_ids' => ($owned[$unit->id] ?? collect())->pluck('id')->values(),
                    'listed_ids' => $unit->listedApplications->pluck('id')->values(),
                ])->values(),
            ])
            ->push([
                'key' => 'committees',
                'label' => 'Committees',
                'divisions' => $committees->map(fn (Committee $committee) => [
                    'key' => "committee-{$committee->id}",
                    'type' => 'committee',
                    'id' => $committee->id,
                    'name' => $committee->name,
                    'short_name' => $committee->short_name,
                    'status' => $committee->status->value,
                    'owned_ids' => [],
                    'listed_ids' => $committee->listedApplications->pluck('id')->values(),
                ])->values(),
            ])
            ->filter(fn (array $category) => $category['divisions']->isNotEmpty())
            ->values();

        $listedSomewhere = $units->flatMap->listedApplications->merge($committees->flatMap->listedApplications)->pluck('id')->unique();

        return Inertia::render('Admin/DashboardLayout/Index', [
            'categories' => $categories,
            'systems' => Application::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'short_name', 'status', 'organizational_unit_id'])
                ->map(fn (Application $application) => [
                    ...$application->only(['id', 'name', 'short_name', 'organizational_unit_id']),
                    'status' => $application->status->value,
                    // Neither managed by an office nor listed anywhere: it
                    // sits under "Other Systems" on the dashboard.
                    'unplaced' => $application->organizational_unit_id === null && ! $listedSomewhere->contains($application->id),
                ]),
        ]);
    }

    /**
     * Sets which systems are listed under one division (besides the ones
     * it manages, which can't be removed here).
     */
    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        $this->authorize('update', new Application);

        $division = match ($type) {
            'unit' => OrganizationalUnit::query()->findOrFail($id),
            'committee' => Committee::query()->findOrFail($id),
            default => abort(404),
        };

        $validated = $request->validate([
            'application_ids' => ['present', 'array'],
            'application_ids.*' => ['integer', 'distinct', Rule::exists('applications', 'id')],
        ]);

        // Managed systems are implicit; never store them as listings too.
        $managed = $division instanceof OrganizationalUnit ? $division->applications()->pluck('id') : collect();
        $ids = collect($validated['application_ids'])->map(fn ($applicationId) => (int) $applicationId)->diff($managed)->values()->all();

        $this->recordPivotChange(
            AuditEvent::ApplicationListingsChanged,
            "Changed the systems listed under {$division->name}",
            $division,
            Application::class,
            $division->listedApplications()->sync($ids),
        );

        return back()->with('status', "Systems under {$division->name} were saved.");
    }
}
