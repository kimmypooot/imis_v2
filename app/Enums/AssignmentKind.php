<?php

namespace App\Enums;

/**
 * HR-1b: kinds of movement between offices. Only a reassignment moves
 * the account's office; a detail and a designation leave the person in
 * their mother unit (decided with HR on 2026-10-03), and a return ends
 * any open detail.
 */
enum AssignmentKind: string
{
    case Reassignment = 'reassignment';
    case Detail = 'detail';
    case Designation = 'designation';
    case ReturnToMotherUnit = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Reassignment => 'Reassignment',
            self::Detail => 'Detail',
            self::Designation => 'Designation',
            self::ReturnToMotherUnit => 'Return to mother unit',
        };
    }

    /** Whether it moves the account's office when it takes effect. */
    public function movesOffice(): bool
    {
        return $this === self::Reassignment;
    }

    /** Whether it can have its own end date (temporary by nature). */
    public function hasEndDate(): bool
    {
        return in_array($this, [self::Detail, self::Designation], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
