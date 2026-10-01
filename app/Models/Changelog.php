<?php

namespace App\Models;

use Database\Factories\ChangelogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "What's New" release note. Only published releases reach the portal;
 * publishing is its own action (see ChangelogController::updateStatus), so
 * `status`/`published_at` are not mass-assignable.
 */
#[Fillable(['version', 'title', 'release_date'])]
class Changelog extends Model
{
    /** @use HasFactory<ChangelogFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChangelogItem::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * The newest published release, by publish order.
     */
    public static function latestPublished(): ?self
    {
        return static::query()->published()->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    /**
     * Replaces the release's entries in the submitted order.
     *
     * @param  array<int, array{type: string, description: string}>  $items
     */
    public function replaceItems(array $items): void
    {
        $this->items()->delete();
        $this->items()->createMany(array_map(
            fn (array $item, int $index) => [...$item, 'sort_order' => $index],
            array_values($items),
            array_keys(array_values($items)),
        ));
    }
}
