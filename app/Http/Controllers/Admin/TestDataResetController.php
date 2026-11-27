<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Support\Admin\TestDataReset;
use App\Support\Backup\BackupStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * System Settings, danger zone: deletes the test data a Super
 * Administrator ticks on the checklist (TestDataReset), keeping the people
 * and the setup. Development only: refused when `test_data.reset_allowed`
 * is off (the default in production). Asks for the current password and
 * the phrase typed out, takes a database backup first (and stops if it
 * can't), and logs what was removed.
 */
class TestDataResetController extends Controller
{
    public const PHRASE = 'RESET TEST DATA';

    public function destroy(Request $request, TestDataReset $reset, BackupStore $backups): RedirectResponse
    {
        abort_unless($request->user()->hasRole(RoleName::SuperAdmin), 403, 'Only a Super Administrator can reset test data.');
        abort_unless(config('test_data.reset_allowed'), 403, 'Resetting test data is turned off on this server.');

        $data = $request->validate(
            [
                'categories' => ['required', 'array', 'min:1'],
                'categories.*' => ['string', Rule::in(array_keys(TestDataReset::CATEGORIES))],
                'password' => ['required', 'string', 'current_password'],
                'confirmation' => ['required', 'string', 'in:'.self::PHRASE],
            ],
            [
                'categories.required' => 'Tick at least one kind of data to delete.',
                'password.current_password' => 'That password is incorrect.',
                'confirmation.in' => 'Type the phrase exactly as shown.',
            ],
        );

        try {
            $backup = $backups->create(automatic: false);
        } catch (Throwable $exception) {
            Log::error('Test data reset stopped: the backup failed.', ['exception' => $exception]);

            return back()->with('error', 'Nothing was deleted: the database backup failed. Check that backups work (System Settings → Backups), then try again.');
        }

        $result = $reset->reset($data['categories'], $request->session()->getId());
        $total = array_sum($result['rows']);

        $this->audit()->record(AuditEvent::TestDataReset, "Reset test data ({$total} rows, {$result['files']} files)", null, [
            'categories' => $result['rows'],
            'files' => $result['files'],
            'backup' => $backup['name'],
        ]);

        return back()->with('status', "Test data deleted: {$total} rows and {$result['files']} files. A backup was taken first ({$backup['name']}).");
    }
}
