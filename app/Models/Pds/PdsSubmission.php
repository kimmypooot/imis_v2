<?php

namespace App\Models\Pds;

use App\Enums\PdsSubmissionStatus;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-2b: the whole PDS exactly as an employee e-signed it — immutable
 * once created (only the review fields change, once, and the PNPKI-signed
 * copy can be attached or replaced: PdsPnpki). Written only by
 * PdsSubmissions and PdsPnpki.
 */
class PdsSubmission extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => PdsSubmissionStatus::class,
            'snapshot' => 'encrypted:array',
            'pnpki_signatures' => 'array',
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

    /** The PNPKI-signed PDF of this PDS, filed in the 201 File. */
    public function pnpkiDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'pnpki_document_id');
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
