<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * HR-6: the leave types of CS Form No. 6, with entitlements from the
 * statutes named in `policy_reference`. Idempotent: an existing type (by
 * code) is never changed, so HR's edits survive re-seeding. HR should
 * review the values against current CSC issuances; all are editable.
 *
 * Special Privilege Leave and Solo Parent Leave are separate leaves (the
 * legacy LMS treated SPL as the solo-parent leave; decided 2026-10-03).
 */
class LeaveTypeSeeder extends Seeder
{
    private const PLANTILLA = ['permanent', 'temporary', 'coterminous', 'casual'];

    public function run(): void
    {
        $omnibus = 'Omnibus Rules on Leave (CSC MC No. 41, s. 1998, as amended)';

        $types = [
            ['code' => 'VL', 'name' => 'Vacation Leave', 'requires_balance' => true, 'monthly_accrual' => 1.25, 'eligible_statuses' => self::PLANTILLA,
                'advance_filing_days' => 5, 'monetizable' => true, 'policy_reference' => $omnibus],
            ['code' => 'FL', 'name' => 'Mandatory/Forced Leave', 'requires_balance' => true, 'charges' => 'VL', 'max_days_per_year' => 5,
                'eligible_statuses' => self::PLANTILLA, 'advance_filing_days' => 5, 'policy_reference' => $omnibus],
            ['code' => 'SL', 'name' => 'Sick Leave', 'requires_balance' => true, 'monthly_accrual' => 1.25, 'eligible_statuses' => self::PLANTILLA,
                'medical_certificate_after_days' => 5, 'policy_reference' => $omnibus],
            ['code' => 'ML', 'name' => 'Maternity Leave', 'workflow' => 'special', 'sex' => 'female', 'max_days_per_filing' => 105,
                'counts_calendar_days' => true, 'allows_half_day' => false, 'policy_reference' => 'RA 11210 (105-Day Expanded Maternity Leave Law)'],
            ['code' => 'PL', 'name' => 'Paternity Leave', 'sex' => 'male', 'max_days_per_filing' => 7, 'policy_reference' => 'RA 8187 (Paternity Leave Act of 1996)'],
            ['code' => 'SPL', 'name' => 'Special Privilege Leave', 'requires_balance' => true, 'annual_grant' => 3, 'lapses_yearly' => true,
                'max_days_per_year' => 3, 'policy_reference' => $omnibus],
            ['code' => 'SOLO', 'name' => 'Solo Parent Leave', 'requires_balance' => true, 'annual_grant' => 7, 'lapses_yearly' => true,
                'max_days_per_year' => 7, 'requires_solo_parent_id' => true, 'policy_reference' => 'RA 8972, as amended by RA 11861 (Solo Parents\' Welfare Act)'],
            ['code' => 'STUDY', 'name' => 'Study Leave', 'workflow' => 'special', 'allows_half_day' => false, 'policy_reference' => $omnibus],
            ['code' => 'VAWC', 'name' => '10-Day VAWC Leave', 'sex' => 'female', 'max_days_per_year' => 10,
                'policy_reference' => 'RA 9262 (Anti-Violence Against Women and Their Children Act)'],
            ['code' => 'RL', 'name' => 'Rehabilitation Privilege', 'workflow' => 'special', 'allows_half_day' => false, 'policy_reference' => $omnibus],
            ['code' => 'SLBW', 'name' => 'Special Leave Benefits for Women', 'workflow' => 'special', 'sex' => 'female', 'allows_half_day' => false,
                'policy_reference' => 'RA 9710 (Magna Carta of Women)'],
            ['code' => 'SEL', 'name' => 'Special Emergency (Calamity) Leave', 'max_days_per_year' => 5, 'policy_reference' => 'CSC issuances on special emergency (calamity) leave'],
            ['code' => 'AL', 'name' => 'Adoption Leave', 'workflow' => 'special', 'allows_half_day' => false, 'policy_reference' => 'Domestic adoption laws (RA 8552, as amended)'],
            ['code' => 'LWOP', 'name' => 'Leave Without Pay', 'policy_reference' => $omnibus],
            // The newest CSC leave. Seeded inactive with no entitlements: HR
            // fills in the days and rules from the circular, then activates it.
            ['code' => 'WL', 'name' => 'Wellness Leave', 'is_active' => false,
                'description' => 'Enter the entitlement and rules from the CSC circular before activating.'],
        ];

        foreach ($types as $order => $type) {
            if (LeaveType::query()->where('code', $type['code'])->exists()) {
                continue;
            }

            $charges = $type['charges'] ?? null;
            unset($type['charges']);

            LeaveType::query()->create([
                ...$type,
                'charged_to_id' => $charges ? LeaveType::query()->where('code', $charges)->value('id') : null,
                'sort_order' => $order + 1,
            ]);
        }
    }
}
