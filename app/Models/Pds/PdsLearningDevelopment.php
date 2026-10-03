<?php

namespace App\Models\Pds;

use App\Enums\LdApprovalStatus;
use App\Enums\LdModality;
use App\Enums\LdType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HR-3: one Learning & Development intervention — the PDS Section VI row
 * and the L&D record (replacing ldms's ld_cscro8) in one. Written only by
 * LdRecords. Removing one soft-deletes it.
 */
class PdsLearningDevelopment extends PdsEntry
{
    use SoftDeletes;

    protected $table = 'pds_learning_developments';

    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'hours' => 'decimal:2',
            'ld_type' => LdType::class,
            'modality' => LdModality::class,
            'approval_status' => LdApprovalStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What appears on the PDS: approved and pending (the PDS is the
     * employee's own sworn statement), never rejected or removed.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnPds(Builder $query): void
    {
        $query->whereIn('approval_status', [LdApprovalStatus::Approved, LdApprovalStatus::Pending]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('approval_status', LdApprovalStatus::Approved);
    }

    public function typeLabel(): ?string
    {
        if ($this->ld_type === LdType::Other) {
            return $this->ld_type_other ?: LdType::Other->label();
        }

        return $this->ld_type?->label();
    }

    /**
     * The row as PDS Section VI shows and prints it.
     *
     * @return array<string, mixed>
     */
    public function toPdsRow(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'date_from' => $this->date_from?->toDateString(),
            'date_to' => $this->date_to?->toDateString(),
            'hours' => $this->hours,
            'ld_type' => $this->typeLabel(),
            'sponsor' => $this->sponsor,
        ];
    }

    public function isEditableByOwner(): bool
    {
        return $this->approval_status !== LdApprovalStatus::Approved;
    }
}
