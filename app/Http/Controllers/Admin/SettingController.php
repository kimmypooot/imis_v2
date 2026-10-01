<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\SettingGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Setting::class);

        $settings = Setting::query()
            ->orderBy('sort_order')
            ->get()
            ->groupBy(fn (Setting $setting) => $setting->group->value);

        $groups = collect(SettingGroup::cases())
            ->filter(fn (SettingGroup $group) => $settings->has($group->value))
            ->map(fn (SettingGroup $group) => [
                'key' => $group->value,
                'label' => $group->label(),
                'description' => $group->description(),
                'settings' => $settings[$group->value]->map(fn (Setting $setting) => $this->present($setting))->values(),
            ])
            ->values();

        return Inertia::render('Admin/Settings/Index', [
            'groups' => $groups,
            'canUpdate' => $request->user()->can('update', Setting::class),
        ]);
    }

    /**
     * Saves one group in a single transaction; the settings cache is
     * flushed once it commits (see Setting::booted()).
     */
    public function update(UpdateSettingsRequest $request, SettingGroup $group): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            foreach ($request->submittedSettings() as [$setting, $value]) {
                $setting->value = $setting->type->serialize($value);
                $setting->save();

                $this->audit()->recordChanges(
                    AuditEvent::SettingUpdated,
                    "Updated setting {$setting->label}",
                    $setting,
                    ['key' => $setting->key, 'group' => $setting->group],
                );
            }
        });

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Setting $setting): array
    {
        return [
            'key' => $setting->key,
            'label' => $setting->label,
            'description' => $setting->description,
            'type' => $setting->type->value,
            'value' => $setting->typedValue(),
            'editable' => $setting->editable,
            'required' => (bool) ($setting->constraints['required'] ?? false),
            'min' => $setting->constraints['min'] ?? null,
            'max' => $setting->constraints['max'] ?? null,
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }
}
