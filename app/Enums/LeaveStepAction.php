<?php

namespace App\Enums;

/**
 * HR-6b: what an approver did at a step of CS Form No. 6, Section 7
 * (the legacy LeaveWorkflowService actions).
 */
enum LeaveStepAction: string
{
    case Pending = 'pending';
    // Part B — immediate supervisor. Both go on to HR.
    case Recommended = 'recommended';
    case NotRecommended = 'not_recommended';
    // Part A — HR.
    case Certified = 'certified';
    case InsufficientCredits = 'insufficient_credits';
    // Parts C/D — Head of Agency (or OIC).
    case Approved = 'approved';
    case Disapproved = 'disapproved';
    // Any step: back to the employee for correction.
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Recommended => 'Recommended for approval',
            self::NotRecommended => 'Recommended for disapproval',
            self::Certified => 'Credits certified',
            self::InsufficientCredits => 'Insufficient credits',
            self::Approved => 'Approved',
            self::Disapproved => 'Disapproved',
            self::Returned => 'Returned for correction',
        };
    }

    /**
     * What each role may do.
     *
     * @return array<int, self>
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'supervisor' => [self::Recommended, self::NotRecommended, self::Returned],
            'hr' => [self::Certified, self::InsufficientCredits, self::Returned],
            'head' => [self::Approved, self::Disapproved, self::Returned],
            default => [],
        };
    }

    /** Needs remarks (a reason the employee can act on). */
    public function needsRemarks(): bool
    {
        return in_array($this, [self::NotRecommended, self::InsufficientCredits, self::Disapproved, self::Returned], true);
    }
}
