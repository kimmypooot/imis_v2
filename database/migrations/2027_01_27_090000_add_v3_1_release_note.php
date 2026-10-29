<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.1.0 What's New release: feedback, deduction notices, single
 * sign-out and the connected systems. Added as a draft, so an admin reviews
 * it in Admin → Changelogs and publishes it (publishing shows it to everyone
 * and makes 3.1.0 the version shown). Skipped when 3.1.0 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.1.0';

    public function up(): void
    {
        if (DB::table('changelogs')->where('version', self::VERSION)->exists()) {
            return;
        }

        DB::transaction(function () {
            $this->insert();
        });
    }

    private function insert(): void
    {
        $now = now();
        $id = DB::table('changelogs')->insertGetId([
            'version' => self::VERSION,
            'title' => 'Feedback, clearer deductions and tidier sign-out',
            'release_date' => '2026-10-29',
            'status' => 'draft',
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('changelog_items')->insert(array_map(
            fn (array $item, int $index) => [
                'changelog_id' => $id,
                'type' => $item[0],
                'description' => $item[1],
                'sort_order' => $index,
            ],
            self::items(),
            array_keys(self::items()),
        ));
    }

    public function down(): void
    {
        // Only an unpublished draft is removed; a published release stays.
        DB::table('changelogs')->where('version', self::VERSION)->where('status', 'draft')->delete();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function items(): array
    {
        return [
            ['feature', 'Send feedback, a suggestion or a recommendation from your user menu. The IT team replies, and you are notified under the bell when the status of your message changes.'],
            ['feature', 'My Earnings & Deductions: see your allowances, mandatory contributions and loans in one place, grouped by kind. Only you can see the amounts.'],
            ['feature', 'You are now told when a recurring deduction is assigned, changed, suspended, resumed, cancelled, taken on a finalized payslip, nearly finished, or done.'],
            ['feature', 'The Training Information Management System (TIMS) is now in the Systems Directory beside OTRS, with the same people who have access to OTRS.'],
            ['improvement', 'Signing out of IMIS now also signs you out of the connected Laravel systems you opened from it, including when your session expires or an administrator ends it.'],
            ['improvement', 'The loading screen appears instantly with the right colors and only when a page is slow to open, so quick pages no longer flash it.'],
            ['improvement', 'HR can now see which employee the Recruitment Portal has selected for a vacant plantilla item, beside the vacancy on the Staffing page.'],
            ['improvement', 'The Regional Office directory on the public CSC RO VIII portal now reads from IMIS, so offices and their leadership are always current.'],
            ['improvement', 'The Human Resource Division no longer lists the separate Leave Application and Management System and Learning and Development Database. Both are built into IMIS: file leave under Leave, and log trainings under Learning & Development.'],
        ];
    }
};
