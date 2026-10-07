<?php

namespace App\Models;

use App\Enums\PayComponentKind;
use App\Enums\PayComputation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HR-8: an allowance, deduction or loan type (PERA, GSIS personal share,
 * PhilHealth, withholding tax, Pag-IBIG MPL…). `automatic` ones go on
 * every payroll entry; the rest only on the pay profiles that list them.
 * Written only by App\Support\HR\Payroll\PayProfiles.
 */
class PayComponent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => PayComponentKind::class,
            'method' => PayComputation::class,
            'amount' => 'decimal:2',
            'rate' => 'decimal:3',
            'base_min' => 'decimal:2',
            'base_max' => 'decimal:2',
            'automatic' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function payItems(): HasMany
    {
        return $this->hasMany(EmployeePayItem::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => "pay component {$this->code} ({$this->name})");
    }
}
