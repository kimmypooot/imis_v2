<?php

namespace App\Support\HR\Leave;

use App\Enums\RoleName;
use App\Models\User;
use App\Support\HR\Payroll\PayslipApprovers;

/**
 * Whose leave a person may see on a calendar. Everyone sees their own
 * division's; only a few see every office's: the head of the Human
 * Resource Division (or whoever is designated head), a Director IV or
 * Director III, and a Super Administrator.
 */
class CalendarAudience
{
    /** Positions that see every office's leave. */
    public const WIDE_POSITIONS = ['director iv', 'director iii'];

    public function __construct(private readonly PayslipApprovers $hrHead) {}

    public function seesEveryone(User $user): bool
    {
        return $user->hasRole(RoleName::SuperAdmin)
            || in_array(mb_strtolower(trim((string) $user->position)), self::WIDE_POSITIONS, true)
            || $this->hrHead->isApprover($user);
    }
}
