<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-3: the office's wording for one email (EmailTemplates). Deleting
 * the row puts the default back.
 */
#[Fillable(['key', 'subject', 'intro', 'button', 'outro', 'updated_by'])]
class EmailTemplate extends Model
{
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
