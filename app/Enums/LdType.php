<?php

namespace App\Enums;

/**
 * Type of L&D intervention, as the PDS (CS Form No. 212, Revised 2026,
 * item 29) asks: Managerial / Supervisory / Technical / etc. ldms's
 * "Leadership and Management" imports as Managerial.
 */
enum LdType: string
{
    case Managerial = 'managerial';
    case Supervisory = 'supervisory';
    case Technical = 'technical';
    case Foundation = 'foundation';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * A free-text type (ldms's `training_type`) as a code plus, for "Other",
     * the text kept as its specification. Blank is not recorded.
     *
     * @return array{0: ?self, 1: ?string}
     */
    public static function fromText(?string $text): array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return [null, null];
        }

        $type = match (mb_strtolower($text)) {
            'leadership and management' => self::Managerial,
            default => self::tryFrom(mb_strtolower($text)),
        };

        return $type ? [$type, null] : [self::Other, mb_substr($text, 0, 100)];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
