<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DTR: one raw punch from a biometric device, under the ID the person is
 * enrolled with (`employees.biometric_id`). Append-only: the application
 * never edits a punch (corrections are separate adjustments). The one
 * deletion is HRD clearing IDs that belong to no employee
 * (`BiometricImporter::purgeUnmatched`).
 */
class BiometricPunch extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BiometricImport::class, 'biometric_import_id');
    }
}
