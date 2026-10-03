<?php

namespace App\Models;

use App\Enums\LeaveStepAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-6b: one step of a leave application's approval chain (CS Form No. 6,
 * Section 7). The supervisor's step names them; HR's and the Head's are
 * acted on by whoever holds the role (or the Head's officer-in-charge).
 */
class LeaveApplicationStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'step' => 'integer',
            'action' => LeaveStepAction::class,
            'acted_at' => 'datetime',
            'certified_credits' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function actingFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_for');
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            'supervisor' => 'Immediate supervisor',
            'hr' => 'HR (certification of leave credits)',
            'head' => 'Head of Agency',
            default => $this->role,
        };
    }
}
