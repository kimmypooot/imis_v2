<?php

namespace Tests\Feature\HR;

use App\Support\HR\Saln\SalnSheets;
use App\Support\HR\Saln\SalnStatement;
use Tests\TestCase;

/**
 * Which rows print where: the form keeps its official number of rows per
 * table, the declarant's overflow goes to AS-1, the spouse's and children's
 * property to AS-2, and the children and relatives tables grow in place.
 */
class SalnSheetsTest extends TestCase
{
    public function test_empty_statement_pads_to_official_counts(): void
    {
        $layout = SalnSheets::layout(SalnStatement::blank());

        $this->assertSame([3, 4, 6, 4, 3, 5], array_map('count', [
            $layout['main']['children'], $layout['main']['real_properties'], $layout['main']['personal_properties'],
            $layout['main']['liabilities'], $layout['main']['business_interests'], $layout['main']['relatives'],
        ]));
        $this->assertSame([null, null, null], $layout['main']['children']);
        $this->assertSame(['children' => [], 'relatives' => []], $layout['continuation']);
        $this->assertSame([], $layout['as1']);
        $this->assertSame([], $layout['as2']);
    }

    public function test_declarant_overflow_goes_to_as1(): void
    {
        $statement = SalnStatement::blank();
        $statement['personal_properties'] = $this->rows(7, 'declarant');

        $layout = SalnSheets::layout($statement);

        $this->assertSame(['Item 1', 'Item 2', 'Item 3', 'Item 4', 'Item 5', 'Item 6'], array_column($layout['main']['personal_properties'], 'description'));
        $this->assertCount(1, $layout['as1']);
        $this->assertSame('Item 7', $layout['as1'][0]['personal_properties'][0]['description']);
        $this->assertCount(4, $layout['as1'][0]['personal_properties']);
        $this->assertCount(4, $layout['as1'][0]['real_properties']);
        $this->assertSame('100.00', $layout['as1'][0]['totals']['personal_properties']);
    }

    public function test_long_overflow_takes_more_as1_sheets(): void
    {
        $statement = SalnStatement::blank();
        $statement['personal_properties'] = $this->rows(6 + 4 + 1, 'declarant');

        $this->assertCount(2, SalnSheets::layout($statement)['as1']);
    }

    public function test_spouse_and_child_rows_go_to_as2(): void
    {
        $statement = SalnStatement::blank();
        $statement['personal_properties'] = [...$this->rows(1, 'declarant'), ...$this->rows(2, 'spouse', 'Spouse'), ...$this->rows(1, 'child', 'Child')];
        $statement['business_interests'] = [['owner' => 'spouse', 'name' => 'Sari-sari store', 'address' => 'Palo', 'nature' => 'Owner', 'acquired_on' => '2019']];

        $layout = SalnSheets::layout($statement);

        $this->assertSame(['Item 1', null, null, null, null, null], array_map(fn ($row) => $row['description'] ?? null, $layout['main']['personal_properties']));
        $this->assertCount(1, $layout['as2']);
        $this->assertSame(['Spouse 1', 'Spouse 2', 'Child 1', null], array_map(fn ($row) => $row['description'] ?? null, $layout['as2'][0]['personal_properties']));
        $this->assertCount(3, $layout['as2'][0]['business_interests']);
        $this->assertCount(3, $layout['main']['business_interests']);
        $this->assertSame('Sari-sari store', $layout['as2'][0]['business_interests'][0]['name']);
        $this->assertSame([], $layout['as1']);
    }

    public function test_children_and_relatives_beyond_the_form_go_to_a_continuation_sheet(): void
    {
        $statement = SalnStatement::blank();
        $statement['children'] = array_map(fn (int $i) => ['name' => "Child {$i}", 'birth_date' => '2012-01-01', 'age' => 13], range(1, 4));
        $statement['relatives'] = array_map(fn (int $i) => ['name' => "Relative {$i}", 'relationship' => 'Cousin', 'position' => 'Clerk', 'agency_and_address' => 'CSC'], range(1, 6));

        $layout = SalnSheets::layout($statement);

        $this->assertSame(['Child 1', 'Child 2', 'Child 3'], array_column($layout['main']['children'], 'name'));
        $this->assertSame(['Child 4'], array_column($layout['continuation']['children'], 'name'));
        $this->assertCount(5, $layout['main']['relatives']);
        $this->assertSame(['Relative 6'], array_column($layout['continuation']['relatives'], 'name'));
        $this->assertSame([], $layout['as1']);
        $this->assertSame(3, SalnSheets::pageCount($layout));
    }

    public function test_page_count_covers_every_sheet(): void
    {
        $statement = SalnStatement::blank();
        $statement['personal_properties'] = [...$this->rows(7, 'declarant'), ...$this->rows(1, 'spouse')];

        $this->assertSame(4, SalnSheets::pageCount(SalnSheets::layout($statement)));
        $this->assertSame(2, SalnSheets::pageCount(SalnSheets::layout(SalnStatement::blank())));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function rows(int $count, string $owner, string $prefix = 'Item'): array
    {
        return array_map(fn (int $i) => [
            'owner' => $owner, 'description' => "{$prefix} {$i}", 'acquisition_year' => '2024', 'acquisition_cost' => '100.00',
        ], range(1, $count));
    }
}
