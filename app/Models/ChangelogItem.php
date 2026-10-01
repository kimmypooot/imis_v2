<?php

namespace App\Models;

use App\Enums\ChangelogItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'description', 'sort_order'])]
class ChangelogItem extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChangelogItemType::class,
        ];
    }

    public function changelog(): BelongsTo
    {
        return $this->belongsTo(Changelog::class);
    }
}
