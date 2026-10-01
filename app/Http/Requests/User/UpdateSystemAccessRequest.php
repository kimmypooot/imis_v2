<?php

namespace App\Http\Requests\User;

use App\Enums\Status;
use App\Models\Application;
use App\Models\ApplicationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Sets one user's role in several systems at once (the per-user screen).
 * Each entry is `{application_id, application_role_id}`; a null role
 * revokes access. Systems not listed are left untouched.
 */
class UpdateSystemAccessRequest extends FormRequest
{
    /** @var Collection<int, ApplicationRole>|null */
    private ?Collection $submittedRoles = null;

    public function authorize(): bool
    {
        if (! $this->user()->can('manageSystemAccess', $this->route('user'))) {
            return false;
        }

        // The per-system ability, for every system touched. Today both
        // resolve to the same permission, but each is checked so a future
        // per-system rule applies here automatically.
        return $this->applications()->every(fn (Application $application) => $this->user()->can('manageAccess', $application));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'access' => ['present', 'array'],
            'access.*.application_id' => ['required', 'integer', 'distinct', 'exists:applications,id'],
            'access.*.application_role_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $target = $this->route('user');
            $current = $target->applicationAccess()->pluck('application_role_id', 'application_id');

            foreach ($this->input('access') as $index => $entry) {
                $roleId = $entry['application_role_id'] ?? null;
                $applicationId = (int) $entry['application_id'];

                if ($roleId === null || (int) $roleId === (int) ($current[$applicationId] ?? 0)) {
                    continue;
                }

                $role = $this->roles()->get((int) $roleId);
                $field = "access.{$index}.application_role_id";

                if (! $role || $role->application_id !== $applicationId || $role->status !== Status::Active) {
                    $validator->errors()->add($field, 'Choose an active role of this system.');
                } elseif (! $target->isActive()) {
                    $validator->errors()->add($field, 'Reactivate this account before granting it system access.');
                }
            }
        });
    }

    /**
     * The validated changes, resolved to models.
     *
     * @return array<int, array{application: Application, role: ?ApplicationRole}>
     */
    public function changes(): array
    {
        $applications = $this->applications()->keyBy('id');

        return collect($this->validated('access'))
            ->map(fn (array $entry) => [
                'application' => $applications->get((int) $entry['application_id']),
                'role' => isset($entry['application_role_id']) ? $this->roles()->get((int) $entry['application_role_id']) : null,
            ])
            ->all();
    }

    /**
     * @return Collection<int, Application>
     */
    private function applications(): Collection
    {
        $ids = collect((array) $this->input('access', []))->pluck('application_id')->filter(fn ($id) => is_numeric($id));

        return Application::query()->whereKey($ids->all())->get();
    }

    /**
     * @return Collection<int, ApplicationRole>
     */
    private function roles(): Collection
    {
        return $this->submittedRoles ??= ApplicationRole::query()
            ->whereKey(collect((array) $this->input('access', []))->pluck('application_role_id')->filter(fn ($id) => is_numeric($id))->all())
            ->get()
            ->keyBy('id');
    }
}
