<?php

namespace App\Http\Requests\Changelog;

use App\Models\Changelog;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChangelogStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('publish', $this->route('changelog'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:'.Changelog::STATUS_DRAFT.','.Changelog::STATUS_PUBLISHED],
        ];
    }
}
