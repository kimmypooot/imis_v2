<?php

namespace App\Enums;

/**
 * Who a portal directory entry (application/service) is shown to.
 * Enforced by `App\Models\Concerns\HasPortalVisibility::scopeVisibleTo()`
 * — every portal controller must query through that scope rather than
 * checking `status`/`visibility` ad hoc, so a new listing can't forget it.
 */
enum Visibility: string
{
    case PublicVisibility = 'public';
    case Authenticated = 'authenticated';
    case Administrative = 'administrative';
}
