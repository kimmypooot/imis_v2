<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The v3.7.0 What's New release: daily time records with biometric imports, e-signatures, tardiness
 * deductions against leave credits, and leave and office-sync improvements. Added already published,
 * so it becomes the version shown. Skipped when 3.7.0 already exists.
 */
return new class extends Migration
{
    private const VERSION = '3.7.0';

    public function up(): void
    {
        if (DB::table('changelogs')->where('version', self::VERSION)->exists()) {
            return;
        }

        DB::transaction(function () {
            $now = now();
            $id = DB::table('changelogs')->insertGetId([
                'version' => self::VERSION,
                'title' => 'Daily time records, e-signatures and tardiness deductions',
                'release_date' => '2026-11-24',
                'status' => 'published',
                'published_at' => $now,
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
        });
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
            ['feature', 'Daily Time Records: HR can import attendance logs from the biometric devices, set work schedules, and review and adjust each employee\'s daily time record. Staff can see their own record in My DTR.'],
            ['feature', 'E-signatures: staff can save a signature once and use it to sign their DTR and leave approvals. A signed DTR can be checked by scanning its QR code.'],
            ['feature', 'Tardiness and undertime can now be converted and deducted from leave credits, with the deductions shown on the leave card.'],
            ['feature', 'DTRs can be printed and exported, by employee or for a whole office.'],
            ['improvement', 'Leave applications print on CS Form No. 6 on Folio paper and follow the latest CSC filing rules. A leave application\'s details can be opened in a pop-up.'],
            ['improvement', 'HR can now correct leave cards, and Solo Parent Leave is shown only to solo parents.'],
            ['improvement', 'TIMS now receives IMIS\'s staff directory and its Executive dashboard figures.'],
            ['improvement', 'The Employees list no longer scrolls sideways: long headings wrap, and the loyalty award badge stays on one line.'],
            ['fix', 'When someone is promoted or linked to a plantilla item, their office now follows the item\'s place of assignment, so the directory and connected systems show the right office. Undoing the promotion puts it back.'],
            ['fix', 'Updating a Contract of Service designation now updates the person\'s position too.'],
        ];
    }
};
