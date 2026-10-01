<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Read-only by design. There are deliberately no create/update/delete
 * abilities and no routes for them — entries are written only by
 * AuditLogger as a side effect of real actions, and AuditLog itself
 * refuses updates and deletes. (Super Administrators pass every Gate check
 * via Gate::before, which is why immutability is enforced in the model and
 * routing, not only here.)
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(PermissionName::AuditLogsView->value);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->hasPermission(PermissionName::AuditLogsView->value);
    }
}
