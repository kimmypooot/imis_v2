<?php

namespace App\Models;

use App\Enums\Status;
use App\Enums\UnitType;
use App\Models\Concerns\HasUniqueSlug;
use Database\Factories\OrganizationalUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

#[Fillable([
    'parent_id', 'code', 'name', 'short_name', 'slug', 'description', 'unit_type',
    'status', 'sort_order', 'email', 'telephone', 'address', 'website_url',
])]
class OrganizationalUnit extends Model
{
    /** @use HasFactory<OrganizationalUnitFactory> */
    use HasFactory, HasUniqueSlug;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'unit_type' => UnitType::class,
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', Status::Active);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Systems listed under this office in the dashboard directory besides
     * the ones it manages (application_listings).
     */
    public function listedApplications(): MorphToMany
    {
        return $this->morphToMany(Application::class, 'listable', 'application_listings')->withTimestamps();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * This unit's ancestor chain, nearest first.
     *
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $ancestors = collect();
        $unit = $this->parent;

        while ($unit) {
            $ancestors->push($unit);
            $unit = $unit->parent;
        }

        return $ancestors;
    }

    /**
     * Every unit below this one in the hierarchy, depth-first.
     *
     * @return Collection<int, self>
     */
    public function descendants(): Collection
    {
        return $this->children->flatMap(
            fn (self $child) => collect([$child])->merge($child->descendants()),
        );
    }

    /**
     * Whether re-parenting this unit under $candidateParent would make the
     * unit its own ancestor (directly, or via a descendant) — the two ways
     * an adjacency-list hierarchy can become circular.
     */
    public function wouldCreateCycleIfParentedTo(?self $candidateParent): bool
    {
        if (! $candidateParent) {
            return false;
        }

        if ($candidateParent->id === $this->id) {
            return true;
        }

        return $this->descendants()->contains('id', $candidateParent->id);
    }
}
