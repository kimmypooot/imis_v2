<?php

namespace App\Support\Branding;

use App\Models\BrandAsset;
use App\Models\User;
use App\Support\Settings\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Admin-3, Admin → Branding: the images that brand IMIS, replaceable by
 * the office (PNG, JPEG or WebP — never SVG, which can carry scripts),
 * and the name beside the header logo (System Settings → General).
 * Everything else about the look — colors, type — stays in the design
 * tokens (resources/css/app.css). Shared with every page as `branding`.
 */
final class Branding
{
    private const CACHE_KEY = 'branding.urls';

    private const DISK = 'public';

    public const ASSETS = [
        'logo' => [
            'label' => 'Agency logo',
            'description' => 'The header, the About dialog, splash screens and the sign-in page.',
            'default' => '/images/brand/csc-logo.png',
            'hint' => 'Square or wide, transparent background, at least 120 px tall.',
        ],
        'app_logo' => [
            'label' => 'IMIS mark',
            'description' => 'The sign-in card, the loading screen, the launch screen and the privacy notice.',
            'default' => '/images/brand/CSC-IMIS.png',
            'hint' => 'Square, transparent background, at least 160 px.',
        ],
        'login_background' => [
            'label' => 'Sign-in background',
            'description' => 'The photo behind the brand panel of the sign-in page (wide screens only; tinted navy).',
            'default' => '/images/brand/cscbg_facade.jpeg',
            'hint' => 'Landscape photo, at least 1200 px wide, under 2 MB.',
        ],
    ];

    /**
     * What every page needs: each image's address and the header name.
     *
     * @return array<string, string>
     */
    public static function shared(): array
    {
        return [...self::urls(), 'header_name' => (string) Settings::get('header_name')];
    }

    /**
     * @return array<string, string> key → address (uploaded, else built-in)
     */
    public static function urls(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $saved = BrandAsset::query()->pluck('path', 'key');

            return collect(self::ASSETS)
                ->map(fn (array $asset, string $key) => isset($saved[$key]) ? Storage::disk(self::DISK)->url($saved[$key]) : $asset['default'])
                ->all();
        });
    }

    public static function url(string $key): string
    {
        return self::urls()[$key] ?? throw new InvalidArgumentException("Unknown brand image [{$key}].");
    }

    public static function store(string $key, UploadedFile $file, User $by): void
    {
        $path = $file->storeAs('branding', $key.'-'.Str::random(12).'.'.$file->guessExtension(), self::DISK);
        $previous = BrandAsset::query()->where('key', $key)->value('path');

        BrandAsset::query()->updateOrCreate(['key' => $key], ['path' => $path, 'updated_by' => $by->id]);
        self::forget($previous);
    }

    public static function reset(string $key): void
    {
        $previous = BrandAsset::query()->where('key', $key)->value('path');

        BrandAsset::query()->where('key', $key)->delete();
        self::forget($previous);
    }

    private static function forget(?string $previous): void
    {
        if ($previous) {
            Storage::disk(self::DISK)->delete($previous);
        }

        Cache::forget(self::CACHE_KEY);
    }
}
