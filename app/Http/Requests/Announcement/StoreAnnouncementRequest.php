<?php

namespace App\Http\Requests\Announcement;

use App\Http\Requests\Announcement\Concerns\AnnouncementContentRules;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;

class StoreAnnouncementRequest extends FormRequest
{
    use AnnouncementContentRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', Announcement::class);
    }
}
