<?php

namespace App\Models;

use Database\Factories\ApplicationAccessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's access to one system, through exactly one of that system's
 * roles (unique per application + user). Revoking access deletes the row,
 * like the role_user pivot — the audit log keeps the history.
 *
 * `granted_by` is never mass-assigned; SystemAccessManager sets it from
 * the authenticated actor.
 */
#[Fillable(['user_id', 'application_role_id'])]
class ApplicationAccess extends Model
{
    /** @use HasFactory<ApplicationAccessFactory> */
    use HasFactory;

    protected $table = 'application_access';

    /**
     * `via_designation`: granted (or raised to admin) because the user is
     * their office's Division Chief / Field Director; set only by
     * OfficeAdminAccess, never mass-assigned.
     */
    protected function casts(): array
    {
        return ['via_designation' => 'boolean'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(ApplicationRole::class, 'application_role_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
