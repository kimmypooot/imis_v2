<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\System\HealthChecks;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → System Health: is everything the IMIS depends on in place and
 * working (database, storage, mail, scheduler, legacy bridge)? The same
 * checks as `imis:health`. For whoever can see System Settings.
 */
class SystemHealthController extends Controller
{
    public function index(HealthChecks $health): Response
    {
        $this->authorize('viewAny', Setting::class);

        $checks = $health->run();

        return Inertia::render('Admin/SystemHealth', [
            'checks' => $checks,
            'overall' => HealthChecks::overall($checks),
            'checkedAt' => now()->toIso8601String(),
        ]);
    }
}
