<?php

namespace App\Http\Requests\Executive;

use App\Models\ExecutiveSnapshot;
use App\Models\ExecutiveTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ExecutiveSnapshot::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // "source/metric", as the dashboard names each figure.
            'metric' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9_:]+\/[a-z0-9_]+(\.[a-z0-9_]+)*$/'],
            'target' => ['required', 'numeric', 'between:-999999999999,999999999999'],
            'direction' => ['required', Rule::in([ExecutiveTarget::HIGHER, ExecutiveTarget::LOWER])],
            'tolerance' => ['required', 'integer', 'between:0,100'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
