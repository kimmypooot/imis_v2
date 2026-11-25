<?php

namespace Tests\Feature\HR;

use App\Support\HR\Saln\SalnStatement;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The SALN statement: what the form accepts, the totals the server
 * computes (in centavos), and which children belong on it.
 */
class SalnStatementTest extends TestCase
{
    public function test_totals_use_centavos(): void
    {
        $statement = SalnStatement::withTotals($this->statement(personal: [
            $this->personal('0.10'), $this->personal('0.20'),
        ]));

        $this->assertSame('0.30', $statement['totals']['personal_properties']);
        $this->assertSame('0.30', $statement['totals']['total_assets']);
    }

    public function test_net_worth_can_be_negative(): void
    {
        $statement = SalnStatement::withTotals($this->statement(
            real: [$this->real('60.00')],
            personal: [$this->personal('40.00')],
            liabilities: [['owner' => 'declarant', 'nature' => 'Loan', 'creditor' => 'GSIS', 'outstanding_balance' => '250.50']],
        ));

        $this->assertSame('100.00', $statement['totals']['total_assets']);
        $this->assertSame('250.50', $statement['totals']['total_liabilities']);
        $this->assertSame('-150.50', $statement['totals']['net_worth']);
    }

    public function test_every_owner_counts_toward_the_totals(): void
    {
        $statement = SalnStatement::withTotals($this->statement(personal: [
            $this->personal('100.00'), $this->personal('50.00', 'spouse'), $this->personal('25.00', 'child'),
        ]));

        $this->assertSame('175.00', $statement['totals']['total_assets']);
    }

    public function test_na_values_are_allowed_and_not_totalled(): void
    {
        $real = $this->real('1500.00');
        $real['assessed_value'] = 'N/A';
        $real['market_value'] = 'n/a';

        $statement = SalnStatement::validate($this->statement(real: [$real]));

        $this->assertSame('N/A', $statement['real_properties'][0]['assessed_value']);
        $this->assertSame('N/A', $statement['real_properties'][0]['market_value']);
        $this->assertSame('1500.00', $statement['totals']['real_properties']);
    }

    public function test_non_numeric_cost_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        SalnStatement::validate($this->statement(personal: [$this->personal('about 5k')]));
    }

    public function test_relatives_are_required_unless_none_is_declared(): void
    {
        $statement = $this->statement();
        $statement['no_relatives'] = false;

        try {
            SalnStatement::validate($statement);
            $this->fail('Expected a validation error for relatives.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('relatives', $e->errors());
        }
    }

    public function test_children_age_filter_on_birthday(): void
    {
        $children = SalnStatement::childrenAsOf([
            ['name' => 'Turned Eighteen', 'birth_date' => '2007-12-31'],
            ['name' => 'Still Seventeen', 'birth_date' => '2008-01-01'],
            ['name' => 'No Birth Date', 'birth_date' => null],
            ['name' => 'Not Yet Born', 'birth_date' => '2026-02-10'],
        ], CarbonImmutable::parse('2025-12-31'));

        $this->assertSame([['name' => 'Still Seventeen', 'birth_date' => '2008-01-01', 'age' => 17]], $children);
    }

    public function test_spouse_in_government_makes_filing_separate(): void
    {
        $statement = $this->statement();
        $this->assertSame('not_applicable', SalnStatement::filingMode($statement));

        $statement['spouse'] = ['family_name' => 'SANTOS', 'first_name' => 'MARIA', 'middle_initial' => 'L', 'in_government' => false];
        $this->assertSame('not_applicable', SalnStatement::filingMode($statement));

        $statement['spouse']['in_government'] = true;
        $this->assertSame('separate', SalnStatement::filingMode($statement));
    }

    /**
     * @param  array<int, array<string, mixed>>  $real
     * @param  array<int, array<string, mixed>>  $personal
     * @param  array<int, array<string, mixed>>  $liabilities
     * @return array<string, mixed>
     */
    private function statement(array $real = [], array $personal = [], array $liabilities = []): array
    {
        return array_replace(SalnStatement::blank(), [
            'declarant' => [
                'family_name' => 'DELA CRUZ', 'first_name' => 'JUAN', 'middle_initial' => 'P',
                'position' => 'Human Resource Specialist II', 'agency' => 'Civil Service Commission Regional Office VIII',
                'office_address' => 'Palo, Leyte',
            ],
            'real_properties' => $real,
            'personal_properties' => $personal,
            'liabilities' => $liabilities,
            'no_business_interests' => true,
            'no_relatives' => true,
            'government_id' => ['type' => 'UMID', 'number' => '0111-2222333-4', 'issued_on' => '2020-01-15'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function personal(string $cost, string $owner = 'declarant'): array
    {
        return ['owner' => $owner, 'description' => 'Laptop', 'acquisition_year' => '2024', 'acquisition_cost' => $cost];
    }

    /**
     * @return array<string, mixed>
     */
    private function real(string $cost): array
    {
        return [
            'owner' => 'declarant', 'description' => 'Lot', 'kind' => 'Residential', 'location' => 'Tacloban City',
            'assessed_value' => '1000.00', 'market_value' => '2000.00', 'acquisition_year' => '2015',
            'acquisition_mode' => 'Purchase', 'acquisition_cost' => $cost,
        ];
    }
}
