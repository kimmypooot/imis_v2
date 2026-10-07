<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The hash of a password a user has replaced, kept so the reuse rule
 * (password_policy_history) can refuse it. Written only by User's
 * `updated` hook.
 */
class PasswordHistory extends Model
{
    /** The most the reuse setting can ask for (its maximum is 24). */
    public const MAX = 24;

    public const UPDATED_AT = null;

    protected $fillable = ['password'];

    protected $hidden = ['password'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
