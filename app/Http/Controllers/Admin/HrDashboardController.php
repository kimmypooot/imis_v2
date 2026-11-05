<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\HR\CutoverReadiness;
use App\Support\HR\HrDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Human Resources dashboard at `/admin/hr`, the landing page of the HR
 * area — separate from the system administration dashboard (`/admin`).
 * Anyone with an HR permission (`access-hr`) can open it; the analytics
 * (HrDashboard) need `employees.view` and are null without it.
 */
class HrDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('access-hr');

        $user = $request->user();

        return Inertia::render('Admin/HR/Dashboard', [
            'hr' => fn () => $this->analytics($user),
            'readiness' => fn () => $this->readiness($user),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * What is left before the legacy LMS and ldms can be retired, for those
     * who may open the Cutover Readiness page; null once nothing blocks, or
     * if the checks cannot run (e.g. no legacy connection).
     *
     * @return array{remaining: int, total: int}|null
     */
    private function readiness(User $user): ?array
    {
        if (! $user->can('manage', LeaveType::class)) {
            return null;
        }

        try {
            $checks = collect(app(CutoverReadiness::class)->checks());
        } catch (Throwable) {
            return null;
        }

        $remaining = $checks->where('status', 'todo')->count();

        return $remaining > 0 ? ['remaining' => $remaining, 'total' => $checks->count()] : null;
    }

    /**
     * The HR analytics name people and show birthdays and ages, so viewing
     * them is audited — once a day per viewer, as the dashboard is opened
     * and refreshed often.
     *
     * @return array<string, mixed>|null
     */
    private function analytics(User $user): ?array
    {
        $hr = (new HrDashboard($user))->build();

        if ($hr !== null && Cache::add("hr-dashboard-viewed:{$user->id}:".today()->toDateString(), true, now()->endOfDay())) {
            $this->audit()->record(AuditEvent::HrDashboardViewed, 'Viewed the HR dashboard');
        }

        return $hr;
    }
}
