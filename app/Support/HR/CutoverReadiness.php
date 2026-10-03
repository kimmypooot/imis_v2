<?php

namespace App\Support\HR;

use App\Console\Commands\RetireLegacyHr;
use App\Enums\EmploymentStatus;
use App\Enums\PermissionName;
use App\Enums\Status;
use App\Models\Application;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveLedgerEntry;
use App\Models\SalarySchedule;
use App\Models\User;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * HR-7: what still stands between HR and switching off the legacy PRISM,
 * LMS and ldms — counts only (no names), each with where to fix it. A
 * check is "ok", "warn" (worth fixing, not blocking) or "todo".
 */
class CutoverReadiness
{
    /**
     * @return array<int, array{group: string, label: string, status: string, detail: string, href: ?string}>
     */
    public function checks(): array
    {
        $staff = fn (): Builder => Employee::query()->whereNull('separation_date');
        $activeUsers = User::query()->where('status', 'active');

        $checks = [];
        $add = function (string $group, string $label, bool $ok, string $detail, ?string $href = null, bool $blocking = true) use (&$checks) {
            $checks[] = ['group' => $group, 'label' => $label, 'status' => $ok ? 'ok' : ($blocking ? 'todo' : 'warn'), 'detail' => $detail, 'href' => $href];
        };

        // People.
        $withoutRecord = (clone $activeUsers)->whereDoesntHave('employee')->count();
        $add('People', 'Every active account has an employee record', $withoutRecord === 0,
            $withoutRecord ? "{$withoutRecord} active account(s) without one" : 'All set', '/admin/hr/employees');

        $unlinked = $staff()->whereHas('user', fn ($query) => $query->whereNull('legacy_id'))->count();
        $add('People', 'Staff are linked to their legacy accounts (for the imports)', $unlinked === 0,
            $unlinked ? "{$unlinked} not linked — run imis:import-legacy" : 'All linked', null, false);

        $noStatus = $staff()->where(fn ($query) => $query->whereNull('employment_status')->orWhere('employment_status', ''))->count();
        $add('People', 'Employment status is set', $noStatus === 0, $noStatus ? "{$noStatus} not set" : 'All set', '/admin/hr/employees');

        $noStart = $staff()->whereNull('original_appointment_date')->count();
        $add('People', 'First day in service is recorded (leave eligibility)', $noStart === 0,
            $noStart ? "{$noStart} missing" : 'All recorded', '/admin/hr/employees', false);

        // Plantilla and Service Record.
        $plantilla = array_map(fn (EmploymentStatus $status) => $status->value, EmploymentStatus::onPlantilla());
        $offItem = $staff()->whereIn('employment_status', $plantilla)
            ->whereDoesntHave('plantillaAppointments', fn ($query) => $query->whereNull('date_to'))->count();
        $add('Plantilla', 'Plantilla staff hold a current item', $offItem === 0,
            $offItem ? "{$offItem} permanent/temporary/coterminous staff without one" : 'All appointed', '/admin/hr/staffing');

        $schedule = SalarySchedule::query()->inForceOn(today())->exists();
        $add('Plantilla', 'A salary schedule is in force today', $schedule, $schedule ? 'Yes' : 'None in force', '/admin/hr/salary-schedules');

        $officer = filled(Settings::get('hr_certifying_officer_name'));
        $add('Plantilla', 'Service Record certifying officer is set', $officer, $officer ? 'Set' : 'Not set', '/admin/settings', false);

        // Leave.
        $noCredits = $staff()->whereDoesntHave('leaveLedger')->count();
        $add('Leave', 'Everyone has leave credits (imported or opening balance)', $noCredits === 0,
            $noCredits ? "{$noCredits} with an empty leave card" : 'All have credits', '/admin/hr/leave');

        $imported = LeaveLedgerEntry::query()->whereNotNull('legacy_id')->exists();
        $add('Leave', 'Legacy LMS credits imported', $imported, $imported ? 'Imported' : 'Not yet — run imis:import-legacy-leave', null);

        $holidays = Holiday::query()->whereYear('date', today()->year)->count();
        $add('Leave', 'This year\'s holidays are entered', $holidays > 0, $holidays ? "{$holidays} entered" : 'None for '.today()->year, '/admin/hr/leave/holidays');

        $noSupervisor = $staff()->whereNull('immediate_supervisor_id')->count();
        $add('Leave', 'Staff have an immediate supervisor (leave recommendation)', $noSupervisor === 0,
            $noSupervisor ? "{$noSupervisor} without one (their leave goes straight to HR)" : 'All set', '/admin/hr/leave', false);

        foreach ([PermissionName::LeaveCertify->value => 'Someone can certify leave credits (HR)', PermissionName::LeaveApprove->value => 'Someone can approve leave (Head of Agency)'] as $permission => $label) {
            $count = (clone $activeUsers)->whereHas('roles.permissions', fn ($query) => $query->where('name', $permission))->count();
            $add('Leave', $label, $count > 0, $count ? "{$count} account(s)" : "No active account has {$permission}", '/roles');
        }

        // Cutover.
        $launchers = Application::query()->whereIn('short_name', RetireLegacyHr::SYSTEMS)->where('status', Status::Active)->pluck('short_name');
        $add('Cutover', 'Legacy PRISM, LMS and ldms launchers retired', $launchers->isEmpty(),
            $launchers->isEmpty() ? 'Retired' : 'Still on the portal: '.$launchers->implode(', ').' — run imis:retire-legacy-hr last', '/admin/applications', false);

        $add('Cutover', 'Legacy database reachable (for the imports)', $reachable = $this->legacyReachable(),
            $reachable ? 'Connected' : 'Not reachable from here', null, false);

        return $checks;
    }

    private function legacyReachable(): bool
    {
        try {
            app('db')->connection(config('legacy.connection'))->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
