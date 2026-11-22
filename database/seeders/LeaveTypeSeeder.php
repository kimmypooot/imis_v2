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
            ['code' => 'WL', ...self::wellnessLeave()],
        ];

        foreach ($types as $order => $type) {
            if (LeaveType::query()->where('code', $type['code'])->exists()) {
                continue;
            }

            $charges = $type['charges'] ?? null;
            unset($type['charges']);
            $rules = self::cscFilingRules()[$type['code']] ?? [];
            $excess = $rules['excess_charged_to'] ?? null;
            unset($rules['excess_charged_to']);
            $type = [...$type, ...$rules];

            LeaveType::query()->create([
                ...$type,
                'charged_to_id' => $charges ? LeaveType::query()->where('code', $charges)->value('id') : null,
                'excess_charged_to_id' => $excess ? LeaveType::query()->where('code', $excess)->value('id') : null,
                'sort_order' => $order + 1,
            ]);
        }
    }

    /**
     * Who the civil service leave rules cover: appointees, not Job Order or
     * Contract of Service workers (whose engagements aren't appointments).
     *
     * @var array<int, string>
     */
    public const CSC_APPOINTEES = ['permanent', 'temporary', 'coterminous', 'casual', 'contractual'];

    /**
     * Filing rules from the CSC leave rules, by code, laid over the types
     * above (and applied to existing types by the migration that added
     * them). HR can change any of them in Admin → Leave Types.
     *
     * - VL/FL: five days ahead "whenever possible" (late, with a reason);
     *   days beyond the credits are without pay (MC 41, s. 1998).
     * - SL: on return; a medical certificate if filed in advance; once SL is
     *   used up, VL is charged (Sec. 56), then without pay.
     * - ML: +15 days for a solo parent, up to 30 more without pay (RA 11210).
     * - PL: the first four deliveries; proof attached (RA 8187).
     * - SPL: a week ahead, except in emergencies (MC 6, s. 1996).
     * - Solo Parent: six months of service; five days ahead (RA 11861).
     * - SLBW: six months of service; five days before surgery, or on return
     *   in an emergency; medical certificate (RA 9710).
     * - Special Emergency: within 30 days of the calamity (MC 2 and 16, s. 2012).
     * - Adoption: 60 days with proof of placement (MC 5, s. 2021).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cscFilingRules(): array
    {
        $everyone = ['eligible_statuses' => self::CSC_APPOINTEES];

        return [
            'VL' => [...$everyone, 'late_filing_with_reason' => true, 'excess_without_pay' => true],
            'FL' => [...$everyone, 'late_filing_with_reason' => true, 'excess_without_pay' => true],
            'SL' => [...$everyone, 'excess_without_pay' => true, 'excess_charged_to' => 'VL', 'medical_certificate_if_advance' => true],
            'ML' => [...$everyone, 'solo_parent_extra_days' => 15, 'unpaid_extension_days' => 30],
            'PL' => [...$everyone, 'max_filings' => 4, 'requires_attachment' => true,
                'supporting_documents' => 'The child\'s birth certificate and your marriage contract'],
            'SPL' => [...$everyone, 'advance_filing_days' => 7, 'allows_emergency_filing' => true],
            'SOLO' => [...$everyone, 'min_service_months' => 6, 'advance_filing_days' => 5, 'late_filing_with_reason' => true],
            'STUDY' => $everyone,
            'VAWC' => [...$everyone, 'requires_attachment' => true,
                'supporting_documents' => 'A Barangay, Temporary or Permanent Protection Order; a certification that one was applied for; or a police report and medical certificate'],
            'RL' => $everyone,
            'SLBW' => [...$everyone, 'min_service_months' => 6, 'advance_filing_days' => 5, 'allows_emergency_filing' => true, 'requires_attachment' => true,
                'supporting_documents' => 'Medical certificate and clinical summary from the attending surgeon'],
            'SEL' => [...$everyone, 'event_label' => 'Date of the calamity (or of its declaration)', 'event_window_days' => 30,
                'policy_reference' => 'CSC MC No. 2, s. 2012, as supplemented by CSC MC No. 16, s. 2012'],
            'AL' => [...$everyone, 'max_days_per_filing' => 60, 'requires_attachment' => true,
                'supporting_documents' => 'Pre-Adoptive Placement Authority (DSWD) or the Decree of Adoption',
                'policy_reference' => 'RA 8552; CSC MC No. 5, s. 2021'],
            'LWOP' => $everyone,
        ];
    }

    /**
     * Wellness Leave: CSC MC No. 01, s. 2026 (CSC Resolution No. 2501292),
     * effective 1 January 2026. Also applied by the migration that added
     * its rules, to a WL type HR hadn't filled in yet.
     *
     * @return array<string, mixed>
     */
    public static function wellnessLeave(): array
    {
        return [
            'name' => 'Wellness Leave',
            'description' => 'Up to 5 days a year for mental health care, physical wellness activities or a general break from work. '
                .'Separate from VL, SL and SPL, and available from the date of assumption of duty. At most 3 days in a row; '
                .'file 5 days ahead, or on your return in an emergency. No reason needs to be given, and anything about a mental health condition is confidential (Data Privacy Act).',
            'workflow' => 'standard',
            'requires_balance' => true,
            'annual_grant' => 5,
            'lapses_yearly' => true,
            'max_days_per_year' => 5,
            'max_consecutive_days' => 3,
            // 1.a: every appointment status. Contract of Service and Job
            // Order workers only as their contracts provide (1.c).
            'eligible_statuses' => ['permanent', 'temporary', 'coterminous', 'casual', 'contractual'],
            'min_service_months' => 0,
            'advance_filing_days' => 5,
            'allows_emergency_filing' => true,
            'monetizable' => false,
            'policy_reference' => 'CSC MC No. 01, s. 2026 (CSC Resolution No. 2501292), effective 1 January 2026',
            'is_active' => true,
        ];
    }
}
