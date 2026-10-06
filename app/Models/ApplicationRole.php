<?php

namespace App\Models;

use App\Enums\Status;
use Database\Factories\ApplicationRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role *inside* one external system (e.g. OTRS "Administrator"), as
 * opposed to an IMIS platform `Role`. It grants nothing within IMIS — it
 * records which role a person holds in that system and, optionally, the
 * role-specific entry point they launch into.
 */
#[Fillable(['name', 'code', 'description', 'launch_url', 'status', 'sort_order'])]
class ApplicationRole extends Model
{
    /** @use HasFactory<ApplicationRoleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(ApplicationAccess::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', Status::Active);
    }
}
