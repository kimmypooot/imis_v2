<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DTR: one raw punch from a biometric device, under the ID the person is
 * enrolled with (`employees.biometric_id`). A punch is never edited or
 * deleted by hand: HRD adds a missing one (`source` = manual, with a
 * reason) or voids a wrong one (kept on file, so a re-import does not bring
 * it back, and not counted). The one deletion is HRD clearing IDs that
 * belong to no employee (`BiometricImporter::purgeUnmatched`).
 */
class BiometricPunch extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BiometricImport::class, 'biometric_import_id');
    }
}
