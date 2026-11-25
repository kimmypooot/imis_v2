<?php

namespace App\Support\HR\Saln;

use App\Enums\SalnOwner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The SALN statement as encoded: its shape, what the 2025 SALN Form
 * accepts, and the totals, which only the server computes. Amounts are
 * strings with two decimals and are summed in centavos.
 *
 * Shape: declarant, spouse (or null), other_spouses, children,
 * real_properties, personal_properties, liabilities, no_business_interests,
 * business_interests, no_relatives, relatives, government_id, totals.
 */
class SalnStatement
{
    public const AMOUNT = '/^\d{1,13}(\.\d{1,2})?$/';

    public const NOT_APPLICABLE = 'N/A';

    /** Keys whose values keep their case: choices, dates and computed fields. */
    private const KEEP_CASE = ['owner', 'filing_mode', 'birth_date', 'issued_on', 'totals'];

    /** The form's Joint Filing / Separate Filing / Not Applicable boxes. */
    public const FILING_MODES = ['joint', 'separate', 'not_applicable'];

    /**
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return [
            'declarant' => ['family_name' => null, 'first_name' => null, 'middle_initial' => null, 'position' => null, 'agency' => null, 'office_address' => null],
            'spouse' => null,
            'filing_mode' => null,
            'other_spouses' => [],
            'children' => [],
            'real_properties' => [],
            'personal_properties' => [],
            'liabilities' => [],
            'no_business_interests' => false,
            'business_interests' => [],
            'no_relatives' => false,
            'relatives' => [],
            'government_id' => ['type' => null, 'number' => null, 'issued_on' => null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $amount = ['required', 'regex:'.self::AMOUNT];
        $valueOrNa = ['required', function (string $attribute, mixed $value, \Closure $fail) {
            if (! self::isNotApplicable($value) && ! preg_match(self::AMOUNT, (string) $value)) {
                $fail('Enter an amount, or N/A when there is no tax declaration.');
            }
        }];
        $owner = ['required', Rule::enum(SalnOwner::class)];
        $year = ['required', 'string', 'max:30'];

        return [
            'declarant.family_name' => ['required', 'string', 'max:100'],
            'declarant.first_name' => ['required', 'string', 'max:100'],
            'declarant.middle_initial' => ['nullable', 'string', 'max:10'],
            'declarant.position' => ['required', 'string', 'max:150'],
            'declarant.agency' => ['required', 'string', 'max:255'],
            'declarant.office_address' => ['required', 'string', 'max:255'],

            'spouse' => ['nullable', 'array'],
            'spouse.family_name' => ['required_with:spouse', 'string', 'max:100'],
            'spouse.first_name' => ['required_with:spouse', 'string', 'max:100'],
            'spouse.middle_initial' => ['nullable', 'string', 'max:10'],
            'spouse.in_government' => ['boolean'],
            'spouse.position' => ['nullable', 'required_if_accepted:spouse.in_government', 'string', 'max:150'],
            'spouse.agency' => ['nullable', 'required_if_accepted:spouse.in_government', 'string', 'max:255'],
            'spouse.office_address' => ['nullable', 'required_if_accepted:spouse.in_government', 'string', 'max:255'],

            'filing_mode' => ['nullable', Rule::in(self::FILING_MODES)],
            'other_spouses' => ['array', 'max:10'],
            'other_spouses.*' => ['required', 'string', 'max:200'],

            'children' => ['array', 'max:30'],
            'children.*.name' => ['required', 'string', 'max:200'],
            'children.*.birth_date' => ['nullable', 'date_format:Y-m-d'],
            'children.*.age' => ['required', 'integer', 'min:0', 'max:17'],

            'real_properties' => ['array', 'max:200'],
            'real_properties.*.owner' => $owner,
            'real_properties.*.description' => ['required', 'string', 'max:255'],
            'real_properties.*.kind' => ['required', 'string', 'max:100'],
            'real_properties.*.location' => ['required', 'string', 'max:255'],
            'real_properties.*.assessed_value' => $valueOrNa,
            'real_properties.*.market_value' => $valueOrNa,
            'real_properties.*.acquisition_year' => $year,
            'real_properties.*.acquisition_mode' => ['required', 'string', 'max:100'],
            'real_properties.*.acquisition_cost' => $amount,

            'personal_properties' => ['array', 'max:300'],
            'personal_properties.*.owner' => $owner,
            'personal_properties.*.description' => ['required', 'string', 'max:255'],
            'personal_properties.*.acquisition_year' => $year,
            'personal_properties.*.acquisition_cost' => $amount,

            'liabilities' => ['array', 'max:200'],
            'liabilities.*.owner' => $owner,
            'liabilities.*.nature' => ['required', 'string', 'max:255'],
            'liabilities.*.creditor' => ['required', 'string', 'max:255'],
            'liabilities.*.outstanding_balance' => $amount,

            'no_business_interests' => ['boolean'],
            'business_interests' => ['array', 'max:100', 'exclude_if:no_business_interests,true', 'required_unless:no_business_interests,true'],
            'business_interests.*.owner' => $owner,
            'business_interests.*.name' => ['required', 'string', 'max:255'],
            'business_interests.*.address' => ['required', 'string', 'max:255'],
            'business_interests.*.nature' => ['required', 'string', 'max:255'],
            'business_interests.*.acquired_on' => ['required', 'string', 'max:50'],

            'no_relatives' => ['boolean'],
            'relatives' => ['array', 'max:100', 'exclude_if:no_relatives,true', 'required_unless:no_relatives,true'],
            'relatives.*.name' => ['required', 'string', 'max:200'],
            'relatives.*.relationship' => ['required', 'string', 'max:100'],
            'relatives.*.position' => ['required', 'string', 'max:150'],
            'relatives.*.agency_and_address' => ['required', 'string', 'max:255'],

            'government_id.type' => ['required', 'string', 'max:100'],
            'government_id.number' => ['required', 'string', 'max:100'],
            'government_id.issued_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Validates the statement and returns it in its stored form: the known
     * keys only, amounts with two decimals (thousands commas allowed),
     * "N/A" spelled one way, children's ages worked out from their birth
     * dates as of the reckoning date, and the totals recomputed.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function validate(array $input, ?CarbonImmutable $asOf = null): array
    {
        foreach (['real_properties', 'personal_properties', 'liabilities'] as $table) {
            foreach ($input[$table] ?? [] as $i => $row) {
                foreach (['assessed_value', 'market_value', 'acquisition_cost', 'outstanding_balance'] as $column) {
                    if (is_array($row) && is_string($row[$column] ?? null)) {
                        $input[$table][$i][$column] = str_replace([',', ' '], '', $row[$column]);
                    }
                }
            }
        }

        $ageErrors = [];
        if ($asOf) {
            foreach ($input['children'] ?? [] as $i => $child) {
                $born = is_array($child) && filled($child['birth_date'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $child['birth_date']) : null;
                if (! $born) {
                    continue;
                }
                if ($born->gt($asOf)) {
                    $ageErrors["children.{$i}.birth_date"] = 'This child was not yet born on '.$asOf->format('F j, Y').'.';
                } elseif (($age = self::ageOn($born, $asOf)) >= 18) {
                    $ageErrors["children.{$i}.birth_date"] = 'This child was 18 or older on '.$asOf->format('F j, Y').', so is not declared.';
                } else {
                    $input['children'][$i]['age'] = $age;
                }
            }
        }

        $validator = Validator::make($input, self::rules());
        $validator->after(function ($validator) use ($ageErrors, $input) {
            foreach ($ageErrors as $key => $message) {
                $validator->errors()->add($key, $message);
            }

            // Joint or separate filing is only for spouses who are both in
            // government; otherwise it is "Not Applicable" (2025 SALN FAQ).
            $inGovernment = (bool) ($input['spouse']['in_government'] ?? false);
            $mode = $input['filing_mode'] ?? null;
            if ($mode && $inGovernment && $mode === 'not_applicable') {
                $validator->errors()->add('filing_mode', 'Your spouse is in government service: choose Joint Filing or Separate Filing.');
            } elseif ($mode && ! $inGovernment && $mode !== 'not_applicable') {
                $validator->errors()->add('filing_mode', 'Joint or separate filing is only for spouses who are both in government service; choose Not Applicable.');
            }
        });
        $validator->validate();

        $statement = array_replace(self::blank(), array_intersect_key($input, self::blank()));
        $statement['filing_mode'] = self::filingMode($statement);

        foreach (['real_properties', 'personal_properties', 'liabilities'] as $table) {
            $statement[$table] = array_values(array_map(fn (array $row) => self::normaliseAmounts($row), $statement[$table]));
        }

        if ($statement['no_business_interests']) {
            $statement['business_interests'] = [];
        }

        if ($statement['no_relatives']) {
            $statement['relatives'] = [];
        }

        return self::withTotals(self::uppercase($statement));
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public static function withTotals(array $statement): array
    {
        $real = self::sum($statement['real_properties'] ?? [], 'acquisition_cost');
        $personal = self::sum($statement['personal_properties'] ?? [], 'acquisition_cost');
        $liabilities = self::sum($statement['liabilities'] ?? [], 'outstanding_balance');

        $statement['totals'] = [
            'real_properties' => self::format($real),
            'personal_properties' => self::format($personal),
            'total_assets' => self::format($real + $personal),
            'total_liabilities' => self::format($liabilities),
            'net_worth' => self::format($real + $personal - $liabilities),
        ];

        return $statement;
    }

    /**
     * The box ticked on the form: what the declarant chose, or by default
     * "separate" when the spouse is also in government, else "not_applicable".
     *
     * @param  array<string, mixed>  $statement
     */
    public static function filingMode(array $statement): string
    {
        $chosen = $statement['filing_mode'] ?? null;
        if (in_array($chosen, self::FILING_MODES, true)) {
            return $chosen;
        }

        return ($statement['spouse']['in_government'] ?? false) ? 'separate' : 'not_applicable';
    }

    /**
     * The children who belong on the SALN as of the reckoning date: below
     * 18 on that date, with their age then. Children without a birth date
     * are left for the employee to add.
     *
     * @param  array<int, array{name: string, birth_date: ?string}>  $children
     * @return array<int, array{name: string, birth_date: string, age: int}>
     */
    public static function childrenAsOf(array $children, CarbonImmutable $asOf): array
    {
        return collect($children)
            ->filter(fn (array $child) => filled($child['birth_date'] ?? null) && CarbonImmutable::parse($child['birth_date'])->lte($asOf))
            ->map(fn (array $child) => [
                'name' => $child['name'],
                'birth_date' => $child['birth_date'],
                'age' => self::ageOn(CarbonImmutable::parse($child['birth_date']), $asOf),
            ])
            ->filter(fn (array $child) => $child['age'] < 18)
            ->values()
            ->all();
    }

    /**
     * Completed years from birth to the date (never negative).
     */
    public static function ageOn(CarbonImmutable $born, CarbonImmutable $date): int
    {
        return max(0, (int) floor($born->startOfDay()->diffInYears($date->startOfDay())));
    }

    /**
     * Entries are kept in capitals, as forms are filled in. Choices (whose,
     * filing), dates and the computed fields are left as they are.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function uppercase(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::KEEP_CASE, true)) {
                continue;
            }
            if (is_array($value)) {
                $values[$key] = self::uppercase($value);
            } elseif (is_string($value)) {
                $values[$key] = mb_strtoupper($value);
            }
        }

        return $values;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function sum(array $rows, string $column): int
    {
        return array_sum(array_map(fn (array $row) => self::centavos($row[$column] ?? null), $rows));
    }

    public static function format(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);

        return $sign.intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function centavos(mixed $amount): int
    {
        if (! is_string($amount) && ! is_numeric($amount)) {
            return 0;
        }

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim((string) $amount), $parts)) {
            return 0;
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    private static function isNotApplicable(mixed $value): bool
    {
        return is_string($value) && in_array(strtoupper(trim($value)), ['N/A', 'NA'], true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normaliseAmounts(array $row): array
    {
        foreach (['assessed_value', 'market_value', 'acquisition_cost', 'outstanding_balance'] as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $row[$column] = self::isNotApplicable($row[$column]) ? self::NOT_APPLICABLE : self::format(self::centavos($row[$column]));
        }

        return $row;
    }
}
