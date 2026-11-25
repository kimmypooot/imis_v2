<?php

namespace App\Enums;

/**
 * When a SALN is filed (2025 Omnibus Rules on the SALN, Rule II): within
 * 30 days of assuming office, every year as of December 31, and within 30
 * days of leaving the service.
 */
enum SalnType: string
{
    case Entry = 'entry';
    case Annual = 'annual';
    case Exit = 'exit';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Assumption of office',
            self::Annual => 'Annual filing',
            self::Exit => 'Exit',
        };
    }
}
