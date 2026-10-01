<?php

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Publish, unpublish, archive, and restore all go through this one
 * request. Which ability is required depends on the transition, not the
 * target status alone:
 *
 * - draft → published, published → draft (publish/unpublish): `publish`
 * - * → archived, archived → draft (archive/restore):             `archive`
 */
class UpdateAnnouncementStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Announcement $announcement */
        $announcement = $this->route('announcement');
        $target = AnnouncementStatus::tryFrom((string) $this->input('status'));

        $ability = match (true) {
            $target === AnnouncementStatus::Archived,
            $announcement->status === AnnouncementStatus::Archived => 'archive',
            default => 'publish',
        };

        return $this->user()->can($ability, $announcement);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(AnnouncementStatus::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('status')) {
                    return;
                }

                /** @var Announcement $announcement */
                $announcement = $this->route('announcement');
                $target = $this->enum('status', AnnouncementStatus::class);

                if (! $announcement->status->canTransitionTo($target)) {
                    $validator->errors()->add(
                        'status',
                        "A {$announcement->status->value} announcement cannot be changed to {$target->value}.",
                    );

                    return;
                }

                if (
                    $target === AnnouncementStatus::Published
                    && $announcement->expires_at !== null
                    && $announcement->expires_at->lessThanOrEqualTo(now())
                ) {
                    $validator->errors()->add(
                        'status',
                        'This announcement has already expired. Update its expiration before publishing.',
                    );
                }
            },
        ];
    }
}
