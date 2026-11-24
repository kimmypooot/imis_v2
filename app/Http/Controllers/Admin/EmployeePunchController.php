<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Support\HR\Dtr\PunchEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * DTR for HRD: an employee's raw biometric punches. Reading them is part of
 * viewing the DTR; adding a missing punch and voiding or restoring one
 * needs `dtr.manage` (never on one's own record). Every change is audited.
 */
class EmployeePunchController extends Controller
{
    public function index(Request $request, Employee $employee, PunchEditor $editor): JsonResponse
    {
        $this->authorize('viewDtr', $employee);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json([
            'date' => $data['date'],
            'biometric_id' => $employee->biometric_id,
            'punches' => $editor->forDay($employee, Carbon::parse($data['date'])),
        ]);
    }

    public function store(Request $request, Employee $employee, PunchEditor $editor): RedirectResponse
    {
        $this->authorize('manageDtr', $employee);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $punch = $editor->add($employee, Carbon::parse("{$data['date']} {$data['time']}"), $data['reason'], $request->user());

        $this->audit()->record(AuditEvent::DtrPunchAdded, "Added a punch at {$punch->punched_at->format('M j, Y g:i A')} to the DTR of {$employee->user->name}", $employee, [
            'punch_id' => $punch->id, 'punched_at' => $punch->punched_at->toDateTimeString(), 'reason' => $punch->reason,
        ]);

        return back()->with('status', "Added the {$punch->punched_at->format('g:i A')} punch.");
    }

    public function void(Request $request, Employee $employee, BiometricPunch $punch, PunchEditor $editor): RedirectResponse
    {
        $this->authorize('manageDtr', $employee);
        abort_unless($punch->biometric_id === $employee->biometric_id, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $editor->void($punch, $data['reason'], $request->user());

        $this->audit()->record(AuditEvent::DtrPunchVoided, "Voided the {$punch->punched_at->format('M j, Y g:i A')} punch of {$employee->user->name}", $employee, [
            'punch_id' => $punch->id, 'punched_at' => $punch->punched_at->toDateTimeString(), 'source' => $punch->source, 'reason' => $data['reason'],
        ]);

        return back()->with('status', 'Punch voided. It no longer counts on the DTR.');
    }

    public function restore(Employee $employee, BiometricPunch $punch, PunchEditor $editor): RedirectResponse
    {
        $this->authorize('manageDtr', $employee);
        abort_unless($punch->biometric_id === $employee->biometric_id, 404);

        $editor->restore($punch);

        $this->audit()->record(AuditEvent::DtrPunchRestored, "Restored the {$punch->punched_at->format('M j, Y g:i A')} punch of {$employee->user->name}", $employee, [
            'punch_id' => $punch->id, 'punched_at' => $punch->punched_at->toDateTimeString(),
        ]);

        return back()->with('status', 'Punch restored. It counts on the DTR again.');
    }
}
