<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-6: a kind of leave (CS Form No. 6), with its policy as data —
 * credits, eligibility and filing rules — editable by HR. The seeded
 * entitlements come from the statutes named in `policy_reference` and
 * are HR's to confirm.
 */
class LeaveType extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'workflow', 'requires_balance', 'charged_to_id', 'monthly_accrual', 'annual_grant',
        'lapses_yearly', 'max_days_per_year', 'max_days_per_filing', 'sex', 'eligible_statuses', 'min_service_months',
        'requires_solo_parent_id', 'advance_filing_days', 'allows_half_day', 'counts_calendar_days',
        'medical_certificate_after_days', 'supporting_documents', 'monetizable', 'policy_reference', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_balance' => 'boolean',
            'monthly_accrual' => 'decimal:3',
            'annual_grant' => 'decimal:3',
            'lapses_yearly' => 'boolean',
            'max_days_per_year' => 'decimal:2',
            'max_days_per_filing' => 'decimal:2',
            'eligible_statuses' => 'array',
            'min_service_months' => 'integer',
            'requires_solo_parent_id' => 'boolean',
            'advance_filing_days' => 'integer',
            'allows_half_day' => 'boolean',
            'counts_calendar_days' => 'boolean',
            'medical_certificate_after_days' => 'integer',
            'monetizable' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** The type whose credits a filing of this type uses (e.g. FL → VL). */
    public function chargedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'charged_to_id');
    }

    /** The ledger this type's filings draw from: another type's, or its own. */
    public function creditType(): self
    {
        return $this->chargedTo ?? $this;
    }

    /** Whether it holds credits of its own (a ledger, a balance). */
    public function hasCredits(): bool
    {
        return $this->monthly_accrual !== null || $this->annual_grant !== null || ($this->requires_balance && $this->charged_to_id === null);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => "leave type {$this->code} ({$this->name})");
    }
}
