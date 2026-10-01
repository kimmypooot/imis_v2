<?php

namespace App\Models;

use App\Enums\Status;
use App\Models\Concerns\HasUniqueSlug;
use Database\Factories\ServiceCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Deliberately minimal taxonomy — name/slug/description/status/sort_order
 * only, per the phase's own "avoid over-engineering taxonomy" instruction.
 * No admin CRUD screens exist for it yet; categories are seeded and
 * selected from a dropdown when managing a Service.
 */
#[Fillable(['name', 'slug', 'description', 'status', 'sort_order'])]
class ServiceCategory extends Model
{
    /** @use HasFactory<ServiceCategoryFactory> */
    use HasFactory, HasUniqueSlug;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', Status::Active);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }
}
