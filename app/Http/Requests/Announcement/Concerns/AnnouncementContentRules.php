<?php

namespace App\Http\Requests\Announcement\Concerns;

use App\Enums\AnnouncementStatus;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Content rules shared by the create and edit requests. `body` is plain
 * text — there is no HTML allowlist to maintain because no HTML is ever
 * rendered (see docs/architecture.md, Phase 11 "Content security").
 */
trait AnnouncementContentRules
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Once published, clearing the publish date would silently hide
        // the announcement forever (scopePublished() needs one), so it
        // becomes required — unpublish instead to take it down.
        $isPublished = $this->route('announcement')?->status === AnnouncementStatus::Published;

        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:20000'],
            'publish_at' => [$isPublished ? 'required' : 'nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'featured' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['publish_at', 'expires_at'])) {
                    return;
                }

                $publishAt = $this->date('publish_at');
                $expiresAt = $this->date('expires_at');

                if ($publishAt && $expiresAt && $expiresAt->lessThanOrEqualTo($publishAt)) {
                    $validator->errors()->add('expires_at', 'The expiration must be after the publish date.');
                }
            },
        ];
    }

    /**
     * Normalize submitted timestamps (the frontend sends ISO-8601 with an
     * offset) to the application timezone before they reach the model.
     *
     * @return array<string, mixed>
     */
    public function announcementData(): array
    {
        $data = $this->validated();

        foreach (['publish_at', 'expires_at'] as $field) {
            $data[$field] = isset($data[$field])
                ? Carbon::parse($data[$field])->setTimezone(config('app.timezone'))
                : null;
        }

        return $data;
    }
}
