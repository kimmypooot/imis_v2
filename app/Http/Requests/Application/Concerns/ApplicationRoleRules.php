<?php

namespace App\Http\Requests\Application\Concerns;

use App\Models\Application;
use App\Models\ApplicationRole;
use Illuminate\Validation\Rule;

/**
 * Shared field rules for defining a system's roles (create and edit).
 */
trait ApplicationRoleRules
{
    /**
     * @return array<string, mixed>
     */
    private function applicationRoleRules(Application $application, ?ApplicationRole $ignore = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('application_roles', 'name')
                    ->where('application_id', $application->id)
                    ->ignore($ignore),
            ],
            // Stable key for systems that map IMIS roles onto their own.
            'code' => [
                'nullable', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('application_roles', 'code')
                    ->where('application_id', $application->id)
                    ->ignore($ignore),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            // Same http/https restriction as the application's own URL, so a
            // role's launch link can never be a javascript:/data: URI.
            'launch_url' => ['nullable', 'url', 'regex:/^https?:\/\//i', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
