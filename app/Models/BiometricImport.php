<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DTR: one uploaded log file and what it added.
 */
class BiometricImport extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rows_read' => 'integer',
            'rows_new' => 'integer',
            'rows_duplicate' => 'integer',
            'rows_skipped' => 'integer',
            'rows_ignored' => 'integer',
            'first_punch_at' => 'datetime',
            'last_punch_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
