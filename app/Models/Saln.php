<?php

namespace App\Models;

use App\Enums\SalnOathMode;
use App\Enums\SalnStatus;
use App\Enums\SalnType;
use Database\Factories\SalnFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SALN filing (entry, annual or exit) and the statement being encoded.
 * Written only by App\Support\HR\Saln\SalnFilings.
 */
class Saln extends Model
{
    /** @use HasFactory<SalnFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => SalnType::class,
            'status' => SalnStatus::class,
            'oath_mode' => SalnOathMode::class,
            'reckoning_date' => 'immutable_date',
            'statement' => 'encrypted:array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SalnVersion::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SalnEvent::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    /**
     * The printed reference, without the version: SALN-2025-00042.
     */
    public function reference(): string
    {
        return sprintf('SALN-%d-%05d', $this->reckoning_date->year, $this->id);
    }

    public function latestVersion(): ?SalnVersion
    {
        return $this->versions()->where('version', $this->current_version)->first();
    }

    /**
     * How audit entries name it.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->reference().' ('.$this->loadMissing('employee.user')->employee?->user?->name.')');
    }
}
