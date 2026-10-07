<?php

namespace App\Enums;

use Closure;
use Illuminate\Validation\Rule;

/**
 * How a setting's value is validated, stored, and read back. Every value
 * is stored as a nullable string in `settings.value`; this enum is the
 * only place that knows how to turn input into that string and back.
 *
 * There is deliberately no "secret"/"password" type — credentials belong
 * in the environment, never in this table (see SettingRegistry).
 */
enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Url = 'url';
    case Email = 'email';
    case Select = 'select';

    /**
     * Validation rules for one value of this type. `$constraints` comes
     * from SettingRegistry (code), never from a request — only the known
     * keys `required`, `min`, `max`, `options` (a select's allowed
     * values) and `min_unless_zero` (an integer where 0 means "off" but any
     * other value has a floor, such as a retention period) are read.
     *
     * @param  array{required?: bool, min?: int, max?: int, options?: array<int, string>, min_unless_zero?: int}  $constraints
     * @return array<int, mixed>
     */
    public function rules(array $constraints = []): array
    {
        if ($this === self::Boolean) {
            return ['required', 'boolean'];
        }

        $presence = ($constraints['required'] ?? false) ? 'required' : 'nullable';

        return [$presence, ...match ($this) {
            self::String => ['string', 'max:'.(int) ($constraints['max'] ?? 255)],
            self::Text => ['string', 'max:'.(int) ($constraints['max'] ?? 5000)],
            self::Integer => [
                'integer',
                'min:'.(int) ($constraints['min'] ?? 0),
                'max:'.(int) ($constraints['max'] ?? 1_000_000),
                ...(isset($constraints['min_unless_zero']) ? [self::minUnlessZero((int) $constraints['min_unless_zero'])] : []),
            ],
            self::Url => ['url:http,https', 'max:2048'],
            self::Email => ['email', 'max:255'],
            self::Select => ['string', Rule::in($constraints['options'] ?? [])],
        }];
    }

    private static function minUnlessZero(int $floor): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($floor) {
            if ((int) $value !== 0 && (int) $value < $floor) {
                $fail("The :attribute must be 0 or at least {$floor}.");
            }
        };
    }

    /**
     * Stored string → typed PHP value.
     */
    public function cast(?string $raw): string|int|bool|null
    {
        if ($raw === null) {
            return $this === self::Boolean ? false : null;
        }

        return match ($this) {
            self::Integer => (int) $raw,
            self::Boolean => $raw === '1',
            default => $raw,
        };
    }

    /**
     * Validated input → stored string. Empty input is stored as null.
     */
    public function serialize(mixed $value): ?string
    {
        if ($this === self::Boolean) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if ($value === null || $value === '') {
            return null;
        }

        return $this === self::Integer ? (string) (int) $value : (string) $value;
    }
}
