<?php

namespace App\Enums;

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

    /**
     * Validation rules for one value of this type. `$constraints` comes
     * from SettingRegistry (code), never from a request — only the known
     * keys `required`, `min`, and `max` are read.
     *
     * @param  array{required?: bool, min?: int, max?: int}  $constraints
     * @return array<int, string>
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
            ],
            self::Url => ['url:http,https', 'max:2048'],
            self::Email => ['email', 'max:255'],
        }];
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
