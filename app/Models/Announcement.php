<?php

namespace App\Models;

use App\Enums\AnnouncementStatus;
use App\Models\Concerns\HasUniqueSlug;
use Carbon\CarbonInterface;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A portal announcement. Visibility is decided here, server-side, and
 * nowhere else: every portal query must go through `scopePublished()`
 * (or `isPublished()` for a single record) so a draft, scheduled,
 * expired, or archived announcement can never leak through a listing
 * someone forgot to filter.
 *
 * `status`, `slug`, and `author_id` are intentionally not fillable — the
 * controller sets them explicitly (status only through the
 * publish/unpublish/archive/restore transitions), never from request input.
 */
#[Fillable(['title', 'summary', 'body', 'publish_at', 'expires_at', 'featured'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory, HasUniqueSlug;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AnnouncementStatus::class,
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'featured' => 'boolean',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Published, already past its publish date, and not yet expired.
     */
    public function scopePublished(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('status', AnnouncementStatus::Published)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', $at)
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $at));
    }

    /**
     * Apply a status transition already validated by
     * UpdateAnnouncementStatusRequest. Publishing without a publish date
     * means "publish now"; a future publish date is kept, which is how an
     * announcement gets scheduled.
     */
    public function transitionTo(AnnouncementStatus $status): void
    {
        $this->status = $status;

        if ($status === AnnouncementStatus::Published && $this->publish_at === null) {
            $this->publish_at = now();
        }

        $this->save();
    }

    public function isPublished(?CarbonInterface $at = null): bool
    {
        return $this->visibilityState($at) === 'live';
    }

    /**
     * Why an announcement is or isn't on the portal right now — shown to
     * admins on the list and preview screens so "published" status alone
     * is never mistaken for "visible". Must stay in sync with
     * scopePublished().
     *
     * @return 'draft'|'archived'|'scheduled'|'expired'|'live'
     */
    public function visibilityState(?CarbonInterface $at = null): string
    {
        $at ??= now();

        return match (true) {
            $this->status === AnnouncementStatus::Draft => 'draft',
            $this->status === AnnouncementStatus::Archived => 'archived',
            $this->publish_at === null || $this->publish_at->greaterThan($at) => 'scheduled',
            $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($at) => 'expired',
            default => 'live',
        };
    }
}
