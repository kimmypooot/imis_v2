<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Support\HR\CutoverReadiness;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-7: the cutover checklist — what's left before the legacy PRISM, LMS
 * and ldms can be switched off. Counts only, no names.
 */
class HrReadinessController extends Controller
{
    public function index(CutoverReadiness $readiness): Response
    {
        $this->authorize('manage', LeaveType::class);

        return Inertia::render('Admin/HR/Readiness', [
            'checks' => $readiness->checks(),
        ]);
    }
}
