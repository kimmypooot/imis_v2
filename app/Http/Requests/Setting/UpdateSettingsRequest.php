<?php

namespace App\Http\Requests\Setting;

use App\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Saves one settings group: `{settings: {key: value, ...}}`. Each value is
 * validated by its setting's type (App\Enums\SettingType). Only keys sent
 * are changed, and the payload is rejected outright if it names a setting
 * outside this group or a protected one — protected settings are refused
 * here, for everyone, rather than in SettingPolicy, because Super
 * Administrators bypass every policy check.
 */
class UpdateSettingsRequest extends FormRequest
{
    /**
     * @var Collection<string, Setting>|null
     */
    private ?Collection $groupSettings = null;

    public function authorize(): bool
    {
        return $this->user()->can('update', Setting::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach ($this->editableSettings() as $key => $setting) {
            $rules["settings.{$key}"] = ['sometimes', ...$setting->rules()];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->groupSettings()
            ->mapWithKeys(fn (Setting $setting, string $key) => ["settings.{$key}" => $setting->label])
            ->all();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $submitted = $this->input('settings');

                if (! is_array($submitted)) {
                    return;
                }

                foreach (array_keys($submitted) as $key) {
                    $setting = $this->groupSettings()->get($key);

                    if ($setting === null) {
                        $validator->errors()->add("settings.{$key}", 'This is not a setting in this group.');
                    } elseif (! $setting->editable) {
                        $validator->errors()->add(
                            "settings.{$key}",
                            "{$setting->label} is protected and can't be changed here.",
                        );
                    }
                }
            },
        ];
    }

    /**
     * The editable settings that were submitted, paired with their
     * validated values.
     *
     * @return array<int, array{0: Setting, 1: mixed}>
     */
    public function submittedSettings(): array
    {
        $values = $this->validated('settings', []);

        return $this->editableSettings()
            ->filter(fn (Setting $setting, string $key) => array_key_exists($key, $values))
            ->map(fn (Setting $setting, string $key) => [$setting, $values[$key]])
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, Setting>
     */
    private function groupSettings(): Collection
    {
        /** @var SettingGroup $group */
        $group = $this->route('group');

        return $this->groupSettings ??= Setting::query()
            ->where('group', $group)
            ->orderBy('sort_order')
            ->get()
            ->keyBy('key');
    }

    /**
     * @return Collection<string, Setting>
     */
    private function editableSettings(): Collection
    {
        return $this->groupSettings()->filter(fn (Setting $setting) => $setting->editable);
    }
}
