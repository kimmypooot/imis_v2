<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Calendar of Activities: one change of an activity's date or time, with
 * the reason. Never edited; the latest one prints as "Moved from …".
 */
class ActivityMove extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'activity_id',
        'from_starts_on', 'from_ends_on', 'from_starts_at', 'from_ends_at',
        'to_starts_on', 'to_ends_on', 'to_starts_at', 'to_ends_at',
        'reason', 'moved_by',
    ];

    protected function casts(): array
    {
        return [
            'from_starts_on' => 'date:Y-m-d',
            'from_ends_on' => 'date:Y-m-d',
            'to_starts_on' => 'date:Y-m-d',
            'to_ends_on' => 'date:Y-m-d',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class)->withTrashed();
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
