<?php

namespace App\Models\Pds;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a repeating PDS section. Subclasses only name their table
 * and casts; which fields exist is defined by PdsSchema, and rows are
 * written only through PdsWriter.
 */
abstract class PdsEntry extends Model
{
    protected $guarded = ['id', 'employee_id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
