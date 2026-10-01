<?php

namespace App\Http\Requests\Application\Concerns;

use App\Models\Application;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Changing someone's role in a system needs both abilities: managing
 * access to *this system* (ApplicationPolicy::manageAccess) and changing
 * *this user's* system access (UserPolicy::manageSystemAccess — not
 * yourself, not a Super Administrator unless you are one).
 */
trait AuthorizesSystemAccessChange
{
    private function mayChangeAccess(Application $application, ?User $target): bool
    {
        if (! $this->user()->can('manageAccess', $application)) {
            return false;
        }

        // An unknown user is left to validation (a 422, not a 403).
        return $target === null || $this->user()->can('manageSystemAccess', $target);
    }

    /**
     * Only an active role of this same system may be assigned.
     */
    private function assignableRoleRule(Application $application): Exists
    {
        return Rule::exists('application_roles', 'id')
            ->where('application_id', $application->id)
            ->where('status', 'active');
    }
}
