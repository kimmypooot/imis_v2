<?php

namespace App\Models;

use App\Enums\PositionLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HR-4: a position title with its salary grade and level. Plantilla items
 * of this title link to it and follow its grade and level
 * (`App\Support\HR\Plantilla\Positions`).
 */
class Position extends Model
{
    protected $fillable = ['title', 'salary_grade', 'level', 'remarks'];

    protected function casts(): array
    {
        return [
            'salary_grade' => 'integer',
            'level' => PositionLevel::class,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlantillaItem::class);
    }

    /**
     * Matches a title however it's capitalized or spaced.
     *
     * @param  Builder<self>  $query
     */
    public function scopeTitled(Builder $query, string $title): void
    {
        $query->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower(trim($title))]);
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => "position {$this->title}");
    }
}
