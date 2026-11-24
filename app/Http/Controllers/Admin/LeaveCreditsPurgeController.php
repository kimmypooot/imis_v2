<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings, danger zone: empties the leave-credit ledger for
 * everyone. It is the only place the append-only ledger is ever wiped, so
 * it is limited to Super Administrators and asks twice: the current
 * password, then the confirmation phrase typed out. Tardiness charges
 * point at ledger lines, so they go with it. Leave applications, leave
 * types and the workbook's card months are left alone. The audit entry
 * records how many lines were removed.
 */
class LeaveCreditsPurgeController extends Controller
{
    public const PHRASE = 'DELETE ALL LEAVE CREDITS';

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless(
            $request->user()->hasRole(RoleName::SuperAdmin),
            403,
            'Only a Super Administrator can delete all leave credits.',
        );

        $request->validate(
            [
                'password' => ['required', 'string', 'current_password'],
                'confirmation' => ['required', 'string', 'in:'.self::PHRASE],
            ],
            [
                'password.current_password' => 'That password is incorrect.',
                'confirmation.in' => 'Type the phrase exactly as shown.',
            ],
        );

        $entries = DB::table('leave_ledger_entries')->count();
        $deductions = DB::table('dtr_leave_deductions')->count();

        // Truncating resets the ids and ignores row order, so the foreign
        // keys (including the ledger's own "reverses" link) are lifted for it.
        Schema::disableForeignKeyConstraints();
        try {
            DB::table('dtr_leave_deductions')->truncate();
            DB::table('leave_ledger_entries')->truncate();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->audit()->record(AuditEvent::LeaveCreditsPurged, "Deleted all leave credits ({$entries} ledger lines)", null, [
            'ledger_entries' => $entries,
            'tardiness_charges' => $deductions,
        ]);

        return back()->with('status', "All leave credits were deleted ({$entries} ledger lines).");
    }
}
