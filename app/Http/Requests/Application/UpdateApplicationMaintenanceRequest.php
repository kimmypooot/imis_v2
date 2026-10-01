<?php

namespace App\Http\Requests\Application;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('maintenance', $this->route('application'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'under_maintenance' => ['required', 'boolean'],
            // Shown to everyone on the portal; plain text, rendered escaped.
            'maintenance_message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
