<?php

namespace App\Models;

use App\Enums\Status;
use Database\Factories\CommitteeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A CSC RO VIII committee record — who's on it and in what capacity.
 * Deliberately does not model meetings, minutes, voting, or scheduling;
 * this is membership record-keeping only, the same way Service never
 * absorbs the transaction it describes.
 */
#[Fillable(['name', 'short_name', 'description', 'status', 'effective_from', 'effective_until'])]
class Committee extends Model
{
    /** @use HasFactory<CommitteeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            // Explicit format so these serialize to plain "Y-m-d" strings
            // for the frontend (and for the <input type="date"> fields
            // that round-trip them) rather than full ISO datetimes.
            'effective_from' => 'date:Y-m-d',
            'effective_until' => 'date:Y-m-d',
        ];
    }

    /**
     * Systems listed under this committee in the dashboard directory.
     */
    public function listedApplications(): MorphToMany
    {
        return $this->morphToMany(Application::class, 'listable', 'application_listings')->withTimestamps();
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommitteeMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', Status::Active);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', Status::Active);
    }
}
