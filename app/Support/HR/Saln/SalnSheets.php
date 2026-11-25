<?php

namespace App\Support\HR\Saln;

use App\Enums\SalnOwner;

/**
 * Which rows print on which sheet of the 2025 SALN Form. Each table keeps
 * the official form's number of rows (counted from the CSC Word files in
 * docs/reference/saln):
 *
 * - the declarant's entries fill the form, then as many AS-1 sheets as
 *   needed;
 * - the spouse's exclusive and the children's entries go on AS-2 sheets;
 * - children and relatives beyond the form's rows (3 and 5) continue on a
 *   continuation sheet, as the rules allow when the form's space isn't
 *   enough (the form prints from the official page, so it can't grow).
 *
 * The form's totals are the whole statement's; each AS sheet carries its
 * own subtotals.
 */
class SalnSheets
{
    public const ROWS = [
        'main' => ['children' => 3, 'real_properties' => 4, 'personal_properties' => 6, 'liabilities' => 4, 'business_interests' => 3, 'relatives' => 5],
        'as1' => ['real_properties' => 4, 'personal_properties' => 4, 'liabilities' => 4, 'business_interests' => 3],
        'as2' => ['real_properties' => 4, 'personal_properties' => 4, 'liabilities' => 4, 'business_interests' => 3],
    ];

    private const OWNED = ['real_properties', 'personal_properties', 'liabilities', 'business_interests'];

    /**
     * @param  array<string, mixed>  $statement
     * @return array{main: array<string, array<int, ?array<string, mixed>>>, as1: array<int, array<string, mixed>>, as2: array<int, array<string, mixed>>, continuation: array{children: array<int, array<string, mixed>>, relatives: array<int, array<string, mixed>>}}
     */
    public static function layout(array $statement): array
    {
        $main = [];
        $continuation = [];
        foreach (['children', 'relatives'] as $table) {
            $rows = array_values($statement[$table] ?? []);
            $main[$table] = self::pad(array_slice($rows, 0, self::ROWS['main'][$table]), self::ROWS['main'][$table]);
            $continuation[$table] = array_slice($rows, self::ROWS['main'][$table]);
        }

        $overflow = [];
        $others = [];

        foreach (self::OWNED as $table) {
            $rows = array_values($statement[$table] ?? []);
            $declarant = array_values(array_filter($rows, fn (array $row) => ($row['owner'] ?? SalnOwner::Declarant->value) === SalnOwner::Declarant->value));
            $others[$table] = array_values(array_filter($rows, fn (array $row) => ($row['owner'] ?? SalnOwner::Declarant->value) !== SalnOwner::Declarant->value));

            $main[$table] = self::pad(array_slice($declarant, 0, self::ROWS['main'][$table]), self::ROWS['main'][$table]);
            $overflow[$table] = array_slice($declarant, self::ROWS['main'][$table]);
        }

        return ['main' => $main, 'as1' => self::sheets($overflow, 'as1'), 'as2' => self::sheets($others, 'as2'), 'continuation' => $continuation];
    }

    /**
     * @param  array{as1: array<int, mixed>, as2: array<int, mixed>, continuation?: array<string, array<int, mixed>>}  $layout
     */
    public static function pageCount(array $layout): int
    {
        return 2 + count($layout['as1']) + count($layout['as2']) + (self::continues($layout) ? 1 : 0);
    }

    /**
     * Whether children or relatives run past the form onto a continuation sheet.
     *
     * @param  array<string, mixed>  $layout
     */
    public static function continues(array $layout): bool
    {
        return ($layout['continuation']['children'] ?? []) !== [] || ($layout['continuation']['relatives'] ?? []) !== [];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function sheets(array $rows, string $sheet): array
    {
        $count = 0;
        foreach (self::OWNED as $table) {
            $count = max($count, (int) ceil(count($rows[$table]) / self::ROWS[$sheet][$table]));
        }

        $sheets = [];
        for ($i = 0; $i < $count; $i++) {
            $page = [];
            foreach (self::OWNED as $table) {
                $size = self::ROWS[$sheet][$table];
                $page[$table] = self::pad(array_slice($rows[$table], $i * $size, $size), $size);
            }

            $real = SalnStatement::sum(array_filter($page['real_properties']), 'acquisition_cost');
            $personal = SalnStatement::sum(array_filter($page['personal_properties']), 'acquisition_cost');
            $page['totals'] = [
                'real_properties' => SalnStatement::format($real),
                'personal_properties' => SalnStatement::format($personal),
                'total_assets' => SalnStatement::format($real + $personal),
                'total_liabilities' => SalnStatement::format(SalnStatement::sum(array_filter($page['liabilities']), 'outstanding_balance')),
            ];
            $sheets[] = $page;
        }

        return $sheets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, ?array<string, mixed>>
     */
    private static function pad(array $rows, int $size): array
    {
        $rows = array_values($rows);

        return array_pad($rows, max($size, count($rows)), null);
    }
}
