<?php

namespace App\Models;

use App\Enums\SalnOathMode;
use Database\Factories\SalnVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One printout of a SALN, frozen when generated: the statement with its
 * totals and the administering officer as they were. Never changes.
 */
class SalnVersion extends Model
{
    /** @use HasFactory<SalnVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'oath_mode' => SalnOathMode::class,
            'statement' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A SALN version never changes once printed.'));
    }

    public function saln(): BelongsTo
    {
        return $this->belongsTo(Saln::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * As printed on every page: SALN-2025-00042 v2.
     */
    public function printedReference(): string
    {
        return $this->reference.' v'.$this->version;
    }
}
