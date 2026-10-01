<?php

namespace App\Http\Requests\Application;

use App\Http\Requests\Application\Concerns\ApplicationRoleRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationRoleRequest extends FormRequest
{
    use ApplicationRoleRules;

    public function authorize(): bool
    {
        return $this->user()->can('manageRoles', $this->route('application'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->applicationRoleRules($this->route('application'), $this->route('applicationRole'));
    }
}
