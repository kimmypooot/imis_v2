<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-3: a scheduled task an administrator paused (ScheduledTasks).
 * Resuming deletes the row; both are audited.
 */
class ScheduledTaskPause extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function pauser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }
}
