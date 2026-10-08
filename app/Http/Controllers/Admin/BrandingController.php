<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\BrandAsset;
use App\Models\Setting;
use App\Support\Branding\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-3, Admin → Branding (`/admin/branding`): replace or reset the
 * images that brand IMIS (Branding). Seen with `settings.view`, changed
 * with `settings.update`; each change is audited. The name beside the
 * logo is a setting (System Settings → General).
 */
class BrandingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Setting::class);
        $saved = BrandAsset::query()->with('updater:id,name')->get()->keyBy('key');
        $urls = Branding::urls();

        return Inertia::render('Admin/Branding', [
            'assets' => collect(Branding::ASSETS)->map(fn (array $asset, string $key) => [
                'key' => $key,
                'label' => $asset['label'],
                'description' => $asset['description'],
                'hint' => $asset['hint'],
                'url' => $urls[$key],
                'custom' => $saved->has($key),
                'updatedBy' => $saved->get($key)?->updater?->name,
                'updatedAt' => $saved->get($key)?->updated_at?->toIso8601String(),
            ])->values()->all(),
            'canUpdate' => $request->user()->can('update', Setting::class),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $this->authorize('update', Setting::class);
        // Raster images only: an SVG can carry scripts.
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);

        Branding::store($key, $request->file('image'), $request->user());
        $this->audit()->record(AuditEvent::BrandingUpdated, 'Replaced the '.Branding::ASSETS[$key]['label'], null, ['image' => $key]);

        return back()->with('status', Branding::ASSETS[$key]['label'].' replaced.');
    }

    public function destroy(string $key): RedirectResponse
    {
        $this->authorize('update', Setting::class);

        Branding::reset($key);
        $this->audit()->record(AuditEvent::BrandingUpdated, 'Put back the built-in '.Branding::ASSETS[$key]['label'], null, ['image' => $key, 'reset' => true]);

        return back()->with('status', 'Built-in '.Branding::ASSETS[$key]['label'].' restored.');
    }
}
