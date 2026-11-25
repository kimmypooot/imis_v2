<?php

namespace App\Enums;

/**
 * Where a SALN is: encoded and printed by the employee, its hard copy
 * received by HRD, reviewed by the SALN Committee (or HRD), then sworn
 * before the Regional Director or a notary and its scan filed.
 */
enum SalnStatus: string
{
    case Draft = 'draft';
    case Generated = 'generated';
    case Received = 'received';
    case Returned = 'returned';
    case Cleared = 'cleared';
    case Filed = 'filed';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Generated, self::Returned], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Generated => 'Printed, awaiting HRD',
            self::Received => 'Received by HRD',
            self::Returned => 'Returned for correction',
            self::Cleared => 'Cleared for oath',
            self::Filed => 'Filed',
        };
    }
}
