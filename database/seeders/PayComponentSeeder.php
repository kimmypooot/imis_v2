<?php

namespace Database\Seeders;

use App\Models\PayComponent;
use Illuminate\Database\Seeder;

/**
 * HR-8: starter allowance, deduction and loan types for a national
 * government agency's payroll. Idempotent: an existing component (by
 * code) is never changed, so HR's edits survive re-seeding.
 *
 * The rates are the ones in force when this was written — HR should check
 * them against the current issuances (every one is editable under
 * Payroll → Pay Components):
 * - PERA ₱2,000 a month (GAA general provisions).
 * - GSIS personal share: 9% of the basic salary (RA 8291).
 * - PhilHealth: 5% premium shared equally, so 2.5% from the employee, on a
 *   salary floor of ₱10,000 and ceiling of ₱100,000 (RA 11223).
 * - Pag-IBIG: 2% from the employee on at most ₱10,000 (₱200), per HDMF
 *   Circular No. 460, s. 2023.
 * - Withholding tax: entered per employee (computed by Accounting under
 *   the TRAIN law's annualized method); IMIS doesn't compute it.
 */
class PayComponentSeeder extends Seeder
{
    public function run(): void
    {
        $components = [
            // Earnings
            ['code' => 'PERA', 'name' => 'PERA', 'kind' => 'earning', 'method' => 'fixed', 'amount' => 2000, 'automatic' => true, 'sort_order' => 10,
                'remarks' => 'Personnel Economic Relief Allowance (GAA).'],
            ['code' => 'RA', 'name' => 'Representation Allowance', 'kind' => 'earning', 'method' => 'manual', 'sort_order' => 20],
            ['code' => 'TA', 'name' => 'Transportation Allowance', 'kind' => 'earning', 'method' => 'manual', 'sort_order' => 21],

            // Mandatory deductions
            ['code' => 'GSIS-PS', 'name' => 'GSIS Personal Share', 'kind' => 'deduction', 'method' => 'percent', 'rate' => 9, 'automatic' => true, 'sort_order' => 100,
                'remarks' => 'RA 8291: 9% of the monthly basic salary.'],
            ['code' => 'PHIC', 'name' => 'PhilHealth', 'kind' => 'deduction', 'method' => 'percent', 'rate' => 2.5, 'base_min' => 10000, 'base_max' => 100000,
                'automatic' => true, 'sort_order' => 110, 'remarks' => 'RA 11223: 5% premium, shared equally; salary floor ₱10,000, ceiling ₱100,000.'],
            ['code' => 'HDMF', 'name' => 'Pag-IBIG', 'kind' => 'deduction', 'method' => 'percent', 'rate' => 2, 'base_max' => 10000,
                'automatic' => true, 'sort_order' => 120, 'remarks' => 'HDMF: 2% of at most ₱10,000 (₱200).'],
            ['code' => 'WTAX', 'name' => 'Withholding Tax', 'kind' => 'deduction', 'method' => 'manual', 'sort_order' => 130,
                'remarks' => 'Entered per employee as computed by Accounting (TRAIN law).'],

            // Other deductions
            ['code' => 'HDMF-MP2', 'name' => 'Pag-IBIG MP2 Savings', 'kind' => 'deduction', 'method' => 'manual', 'sort_order' => 200],
            ['code' => 'COOP', 'name' => 'Cooperative Share Capital', 'kind' => 'deduction', 'method' => 'manual', 'sort_order' => 210],
            ['code' => 'ABS', 'name' => 'Absences / Leave Without Pay', 'kind' => 'deduction', 'method' => 'manual', 'sort_order' => 220,
                'remarks' => 'Usually added on a payroll entry for the month, not on the profile.'],

            // Loans
            ['code' => 'GSIS-CONSO', 'name' => 'GSIS Consolidated Loan', 'kind' => 'loan', 'method' => 'manual', 'sort_order' => 300],
            ['code' => 'GSIS-MPL', 'name' => 'GSIS Multi-Purpose Loan', 'kind' => 'loan', 'method' => 'manual', 'sort_order' => 301],
            ['code' => 'GSIS-EML', 'name' => 'GSIS Emergency Loan', 'kind' => 'loan', 'method' => 'manual', 'sort_order' => 302],
            ['code' => 'GSIS-POL', 'name' => 'GSIS Policy Loan', 'kind' => 'loan', 'method' => 'manual', 'sort_order' => 303],
            ['code' => 'HDMF-MPL', 'name' => 'Pag-IBIG Multi-Purpose Loan', 'kind' => 'loan', 'method' => 'manual', 'sort_order' => 310],
            ['code' => 'HDMF-CAL', 'name' => 'Pag-IBIG Calamity Loan', 'kind' => 'loan', 'method' => 'manual', 'sort_order' => 311],
            ['code' => 'LBP', 'name' => 'Landbank Salary Loan', 'kind' => 'loan', 'method' => 'manual', 'sort_order' => 320],
            ['code' => 'COOP-LOAN', 'name' => 'Cooperative Loan', 'kind' => 'loan', 'method' => 'manual', 'sort_order' => 330],
        ];

        // How each is grouped on the employee's Earnings & Deductions page.
        $categories = [
            'mandatory' => ['GSIS-PS', 'PHIC', 'HDMF', 'WTAX'],
            'government_loan' => ['GSIS-CONSO', 'GSIS-MPL', 'GSIS-EML', 'GSIS-POL', 'HDMF-MPL', 'HDMF-CAL'],
            'other_loan' => ['LBP', 'COOP-LOAN'],
        ];

        foreach ($components as $component) {
            $category = collect($categories)->search(fn (array $codes) => in_array($component['code'], $codes, true));
            $category = $category ?: ($component['kind'] === 'earning' ? 'allowance' : 'other');

            PayComponent::query()->firstOrCreate(['code' => $component['code']], [...$component, 'category' => $category]);
        }
    }
}
