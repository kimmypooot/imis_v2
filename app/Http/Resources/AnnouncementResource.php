<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Portal-facing shape — deliberately omits status/expiry internals, since
 * the portal only ever receives announcements that are already live.
 * `body` is plain text and must be rendered with text interpolation,
 * never `v-html`.
 *
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    /**
     * Listings only need the summary; the full body is opted into by the
     * detail page so a listing never ships every body in full.
     */
    private bool $withBody = false;

    public function withBody(): static
    {
        $this->withBody = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'body' => $this->when($this->withBody, $this->body),
            'featured' => $this->featured,
            'publish_at' => $this->publish_at?->toIso8601String(),
            'author' => $this->whenLoaded('author', fn () => $this->author?->name),
        ];
    }
}
