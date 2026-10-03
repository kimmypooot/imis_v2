<?php

namespace App\Models;

use App\Enums\PositionLevel;
use App\Support\HR\Plantilla\Positions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * HR-4: a plantilla item (an authorized, funded position). Filled when
 * someone holds it now (an open appointment). Abolished items stay for
 * the record; they're never deleted.
 */
class PlantillaItem extends Model
{
    protected $fillable = ['item_number', 'position_title', 'salary_grade', 'level', 'organizational_unit_id', 'authority', 'remarks'];

    protected function casts(): array
    {
        return [
            'salary_grade' => 'integer',
            'level' => PositionLevel::class,
            'abolished_at' => 'datetime',
            'abolished_on' => 'date:Y-m-d',
        ];
    }

    /**
     * Every save links the item to the position of its title and takes
     * that position's grade and level (Human Resources → Positions).
     */
    protected static function booted(): void
    {
        static::saving(fn (PlantillaItem $item) => app(Positions::class)->applyTo($item));
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * Every period of service on this item, incl. past ones. Named for the
     * `{appointment}` route parameter, so admin routes can scope it.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(PlantillaAppointment::class);
    }

    /**
     * Whoever holds the item now. An item has one open period at most;
     * picked by id, not start date, since an incomplete link has none yet.
     */
    public function incumbency(): HasOne
    {
        return $this->hasOne(PlantillaAppointment::class)->whereNull('date_to')->latestOfMany();
    }

    public function isAbolished(): bool
    {
        return $this->abolished_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('abolished_at');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->item_number
            ? "plantilla item {$this->item_number} ({$this->position_title})"
            : "plantilla item {$this->position_title} (no item number yet)");
    }

    /** How the item is named in messages: its number, or its title until it has one. */
    protected function reference(): Attribute
    {
        return Attribute::get(fn () => $this->item_number ?: $this->position_title);
    }
}
