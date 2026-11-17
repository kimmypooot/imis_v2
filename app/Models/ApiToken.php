<?php

namespace App\Models;

use App\Enums\ApiIntegration;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bearer token a connected system uses to call IMIS's server-to-server
 * APIs. Only the hash is kept. Created and revoked through ApiTokens.
 */
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'integration' => ApiIntegration::class,
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * A live token that expires within the next $days days, so it can be
     * rotated before the connected system starts getting refused.
     */
    public function expiresSoon(int $days = 14): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired()
            && $this->expires_at !== null && $this->expires_at->lte(now()->addDays($days));
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            default => 'active',
        };
    }
}
