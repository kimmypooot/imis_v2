<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\PayCategory;
use App\Enums\PayComponentKind;
use App\Enums\PayComputation;
use App\Http\Controllers\Controller;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Support\HR\Payroll\PayProfiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-8: allowance, deduction and loan types, and how each is computed.
 */
class PayComponentController extends Controller
{
    public function index(Request $request, PayProfiles $profiles): Response
    {
        $this->authorize('viewAny', PayrollRun::class);

        return Inertia::render('Admin/HR/Payroll/Components', [
            'components' => PayComponent::query()->ordered()->get()->map(fn (PayComponent $component) => [
                ...$profiles->presentComponent($component),
                'in_use' => $profiles->componentInUse($component),
            ]),
            'kinds' => PayComponentKind::options(),
            'methods' => PayComputation::options(),
            'categories' => PayCategory::options(),
            'canManage' => $request->user()->can('manage', PayrollRun::class),
        ]);
    }

    public function store(Request $request, PayProfiles $profiles): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $component = $profiles->saveComponent(null, $request->validate($profiles->componentRules()));
        $this->audit()->record(AuditEvent::PayComponentCreated, "Added {$component->label}", $component);

        return back()->with('status', "{$component->name} added.");
    }

    public function update(Request $request, PayComponent $component, PayProfiles $profiles): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $profiles->saveComponent($component, $request->validate($profiles->componentRules($component)));
        $this->audit()->recordChanges(AuditEvent::PayComponentUpdated, "Updated {$component->label}", $component);

        return back()->with('status', "{$component->name} updated.");
    }

    public function destroy(PayComponent $component, PayProfiles $profiles): RedirectResponse
    {
        $this->authorize('manage', PayrollRun::class);

        $this->audit()->record(AuditEvent::PayComponentDeleted, "Deleted {$component->label}", $component);
        $profiles->deleteComponent($component);

        return back()->with('status', "{$component->name} deleted.");
    }
}
