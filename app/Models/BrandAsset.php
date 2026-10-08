<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-3: an image the office uploaded in place of a built-in one
 * (Branding), stored on the public disk.
 */
#[Fillable(['key', 'path', 'updated_by'])]
class BrandAsset extends Model
{
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
