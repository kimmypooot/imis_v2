<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BiometricPunch;
use App\Support\HR\Dtr\DtrOverview;
use App\Support\HR\Dtr\DtrSheets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DTR for HRD: everyone's month at a glance, with what needs attention,
 * and the way into each employee's DTR (where adjustments and raw punches
 * are managed, and where opening it is audited). HR only: it takes
 * `dtr.view` or `dtr.manage`.
 */
class DtrManagementController extends Controller
{
    public function index(Request $request, DtrOverview $overview): Response
    {
        $this->authorize('viewAny', BiometricPunch::class);

        $data = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'office' => ['nullable', 'string', 'regex:/^(regional|\d+)$/'],
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'string', 'in:'.implode(',', DtrOverview::FILTERS)],
        ]);
        $month = DtrSheets::parseMonth($data['month'] ?? null);
        $office = $data['office'] ?? null;
        $search = $data['search'] ?? null;
        $filter = $data['filter'] ?? null;

        $rows = $overview->page($month, $office, $search, $filter);

        return Inertia::render('Admin/HR/Dtr/Index', [
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'filters' => ['office' => $office, 'search' => $search, 'filter' => $filter],
            'offices' => $overview->offices(),
            'filterLabels' => DtrOverview::filterLabels(),
            'counts' => $overview->counts($month, $office, $search),
            'employees' => $rows,
            'canManage' => $request->user()->can('manage', BiometricPunch::class),
        ]);
    }
}
