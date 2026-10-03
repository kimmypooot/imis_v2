<?php

namespace App\Models\Pds;

use App\Enums\PdsSubmissionStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-2b: the whole PDS exactly as an employee e-signed it — immutable
 * once created (only the review fields change, once). Written only by
 * PdsSubmissions.
 */
class PdsSubmission extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => PdsSubmissionStatus::class,
            'snapshot' => 'encrypted:array',
            'signed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === PdsSubmissionStatus::Submitted;
    }

    /**
     * How audit entries name it.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'PDS submission of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
