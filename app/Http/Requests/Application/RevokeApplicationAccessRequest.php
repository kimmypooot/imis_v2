<?php

namespace App\Http\Requests\Application;

use App\Http\Requests\Application\Concerns\AuthorizesSystemAccessChange;
use Illuminate\Foundation\Http\FormRequest;

class RevokeApplicationAccessRequest extends FormRequest
{
    use AuthorizesSystemAccessChange;

    public function authorize(): bool
    {
        return $this->mayChangeAccess($this->route('application'), $this->route('access')->user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
