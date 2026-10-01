<?php

namespace App\Http\Requests\Announcement;

use App\Http\Requests\Announcement\Concerns\AnnouncementContentRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAnnouncementRequest extends FormRequest
{
    use AnnouncementContentRules;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('announcement'));
    }
}
