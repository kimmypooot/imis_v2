<?php

namespace App\Models;

use App\Enums\EmployeeDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-2c: a document in an employee's 201 file. The file is on the
 * private disk; written only by EmployeeDocuments, never deleted (only
 * archived, with a reason).
 */
class EmployeeDocument extends Model
{
    protected $fillable = ['document_type', 'title', 'document_date', 'remarks'];

    protected function casts(): array
    {
        return [
            'document_type' => EmployeeDocumentType::class,
            'document_date' => 'date:Y-m-d',
            'archived_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * How audit entries name it ("Oath of Office in the 201 file of …").
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->document_type->label().' in the 201 file of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
