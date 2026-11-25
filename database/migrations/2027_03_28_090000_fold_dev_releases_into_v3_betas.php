<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IMIS 3 is still in development until its 2027 launch, so the 3.0.0 to
 * 3.7.1 notes were development builds, not releases. They are folded into
 * one pre-release per month: 3.0.0-beta.1 (October 2026, published) and
 * 3.0.0-beta.2 (November 2026, a draft published at month end, with the
 * work since 3.7.1 added). The launch itself will be 3.0.0, a major step
 * up from the legacy IMIS 2.7.0. Items are moved as they are in the
 * database, so edits made in Admin → Changelogs are kept. Skipped when
 * 3.0.0-beta.1 already exists.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{title: string, release_date: string, published: bool, versions: list<string>}>
     */
    private const BETAS = [
        '3.0.0-beta.1' => [
            'title' => 'October build: the VILT rebuild with Human Resources, feedback, a fresher look and a new sign-in page',
            'release_date' => '2026-10-31',
            'published' => true,
            'versions' => ['3.0.0', '3.1.0', '3.2.0', '3.3.0'],
        ],
        '3.0.0-beta.2' => [
            'title' => 'November build: daily time records, dark mode, fingerprint sign-in and payroll through Accounting',
            'release_date' => '2026-11-30',
            'published' => false,
            'versions' => ['3.4.0', '3.5.0', '3.5.1', '3.5.2', '3.6.0', '3.6.1', '3.7.0', '3.7.1'],
        ],
    ];

    public function up(): void
    {
        if (DB::table('changelogs')->where('version', '3.0.0-beta.1')->exists()) {
            return;
        }

        DB::transaction(function () {
            foreach (self::BETAS as $version => $beta) {
                $now = now();
                $id = DB::table('changelogs')->insertGetId([
                    'version' => $version,
                    'title' => $beta['title'],
                    'release_date' => $beta['release_date'],
                    'status' => $beta['published'] ? 'published' : 'draft',
                    'published_at' => $beta['published'] ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $oldIds = DB::table('changelogs')->whereIn('version', $beta['versions'])->orderBy('release_date')->orderBy('id')->pluck('id');
                $order = 0;

                foreach ($oldIds as $oldId) {
                    $itemIds = DB::table('changelog_items')->where('changelog_id', $oldId)->orderBy('sort_order')->orderBy('id')->pluck('id');

                    foreach ($itemIds as $itemId) {
                        DB::table('changelog_items')->where('id', $itemId)->update(['changelog_id' => $id, 'sort_order' => $order++]);
                    }
                }

                if ($version === '3.0.0-beta.2') {
                    foreach (self::sinceV371() as [$type, $description]) {
                        DB::table('changelog_items')->insert(['changelog_id' => $id, 'type' => $type, 'description' => $description, 'sort_order' => $order++]);
                    }
                }

                // Users who had seen an old note fall back to null and see the beta once.
                DB::table('changelogs')->whereIn('id', $oldIds)->delete();
            }
        });
    }

    public function down(): void
    {
        // The old development numbers are retired; the betas stay.
    }

    /**
     * Work done after the last development note (3.7.1).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function sinceV371(): array
    {
        return [
            ['feature', 'Payroll now covers the whole month with one release on its last day; the 1st and 2nd quincena split is gone.'],
            ['feature', 'Payroll goes through Accounting: HRD prepares it and submits it to the Accountant III, who enters withholding tax under Payroll Tax Review and returns it, and HRD then finalizes it. Each step is announced under the bell.'],
            ['feature', 'Printable General Payroll grouped by office, ending with an Amount Received column, and remittance schedules for GSIS, PhilHealth, Pag-IBIG, the Welfare Fund and BIR.'],
            ['feature', 'Payroll can be compared with the accounting office\'s monthly workbook, showing each person\'s differences, and that workbook\'s deductions and loans can be imported.'],
            ['feature', 'Push notifications: switch them on in Notification settings to get your bell notices on your phone or computer. The Super Administrator allows them in System Settings.'],
            ['feature', 'The plantilla flags holders whose account title differs from their item\'s, and plantilla managers are told under the bell once per difference.'],
            ['improvement', 'The Compressed Work Week now has a 12:00 to 1:00 noon break on the DTR, and the DTR sheet and Excel export have a clearer layout.'],
            ['feature', 'HR staff have their own role, and HRD is reminded to sync tardiness deductions.'],
        ];
    }
};
