<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HRD's record that an employee need not file for a year (a laborer
 * position, an honorary role), with the reason.
 */
class SalnExemption extends Model
{
    protected $guarded = ['*'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
