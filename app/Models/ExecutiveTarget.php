<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The agreed target for one Executive Dashboard figure, keyed by
 * "source/metric" (e.g. `hr/plantilla.fill_rate`). `direction` says which
 * way is better ("higher" or "lower"); `tolerance` is how many percent
 * short of the target still counts as at risk rather than behind.
 * Maintained on Executive Dashboard → Settings (`executive.manage`).
 */
#[Fillable(['metric', 'target', 'direction', 'tolerance', 'note', 'updated_by'])]
class ExecutiveTarget extends Model
{
    public const HIGHER = 'higher';

    public const LOWER = 'lower';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => 'float',
            'tolerance' => 'integer',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
