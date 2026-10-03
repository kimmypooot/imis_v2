<?php

namespace App\Http\Requests\Application;

use App\Enums\Status;
use App\Models\ApplicationRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * The legacy System Access modal's "Save": set several users' roles in one
 * system at once. Entries are `{user_id, application_role_id}`; a null role
 * revokes access. Only the entries sent are touched, and the whole save is
 * rejected if any entry is invalid — every rule the single-grant endpoints
 * enforce applies to each row.
 */
class SyncApplicationAccessRequest extends FormRequest
{
    /** @var Collection<int, User>|null */
    private ?Collection $targets = null;

    public function authorize(): bool
    {
        return $this->user()->can('manageAccess', $this->route('application'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:1000'],
            'entries.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'entries.*.application_role_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $application = $this->route('application');
            $current = $application->accessGrants()->pluck('application_role_id', 'user_id');
            $roles = ApplicationRole::query()
                ->whereKey(collect($this->input('entries'))->pluck('application_role_id')->filter()->all())
                ->get()
                ->keyBy('id');

            foreach ($this->input('entries') as $index => $entry) {
                $user = $this->targets()->get((int) $entry['user_id']);
                $roleId = $entry['application_role_id'] ?? null;
                $field = "entries.{$index}.application_role_id";

                if (! $this->user()->can('manageSystemAccess', $user)) {
                    $validator->errors()->add($field, "You can't change {$user->name}'s system access.");

                    continue;
                }

                if ($roleId === null || (int) $roleId === (int) ($current[$user->id] ?? 0)) {
                    continue;
                }

                $role = $roles->get((int) $roleId);

                if (! $role || $role->application_id !== $application->id || $role->status !== Status::Active) {
                    $validator->errors()->add($field, 'Choose an active role of this system.');
                } elseif (! $user->isActive()) {
                    $validator->errors()->add($field, "Reactivate {$user->name}'s account before granting access.");
                }
            }
        });
    }

    /**
     * @return array<int, array{user: User, role: ?ApplicationRole}>
     */
    public function changes(): array
    {
        $roles = ApplicationRole::query()
            ->whereKey(collect($this->validated('entries'))->pluck('application_role_id')->filter()->all())
            ->get()
            ->keyBy('id');

        return collect($this->validated('entries'))
            ->map(fn (array $entry) => [
                'user' => $this->targets()->get((int) $entry['user_id']),
                'role' => isset($entry['application_role_id']) ? $roles->get((int) $entry['application_role_id']) : null,
            ])
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    private function targets(): Collection
    {
        return $this->targets ??= User::query()
            ->with('roles')
            ->whereKey(collect($this->input('entries'))->pluck('user_id')->filter(fn ($id) => is_numeric($id))->all())
            ->get()
            ->keyBy('id');
    }
}
