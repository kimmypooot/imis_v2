<?php

namespace App\Http\Controllers;

use App\Models\ExecutiveSnapshot;
use App\Support\Executive\ExecutiveDashboard;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exec-1: the Executive Dashboard at `/executive` — every module's and
 * connected system's status and figures in one place, for top management
 * (`executive.view`). Built from saved snapshots (ExecutiveDashboard), so
 * it never waits on another system. Totals only, so viewing isn't audited.
 */
class ExecutiveDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ExecutiveSnapshot::class);

        return Inertia::render('Executive/Dashboard', [
            'dashboard' => fn () => app(ExecutiveDashboard::class, ['viewer' => $request->user()])->build(),
            'canManage' => $request->user()->can('manage', ExecutiveSnapshot::class),
        ]);
    }

    /** Exec-3: the same figures as a report to print or save as PDF. */
    public function print(Request $request): Response
    {
        $this->authorize('viewAny', ExecutiveSnapshot::class);

        return Inertia::render('Executive/Print', [
            'dashboard' => app(ExecutiveDashboard::class, ['viewer' => $request->user()])->build(),
            'organization' => Settings::get('organization_name'),
            'printedBy' => $request->user()->name,
        ]);
    }
}
