<?php

namespace App\Support\HR;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A person's saved e-signature image (drawn on screen or uploaded), on the
 * private disk, so it is only ever served through authorized routes. It is
 * cleaned on the way in: the background becomes transparent, it is cropped
 * to the ink and scaled down, so it prints cleanly over a signature line.
 *
 * Signing a leave takes a snapshot copy for that step or application, so
 * changing your signature later never alters what was signed.
 */
class Signatures
{
    public const DISK = 'local';

    private const DIRECTORY = 'signatures';

    private const SNAPSHOTS = 'signatures/snapshots';

    private const MAX_BYTES = 2_097_152;

    // Ink colour (a dark blue-black, like a pen) and the largest size kept.
    private const INK = [12, 18, 60];

    private const MAX_WIDTH = 500;

    private const MAX_HEIGHT = 180;

    /**
     * @param  ?string  $dataUrl  a PNG data URL from the drawing pad
     * @return string the new live path
     */
    public function store(?UploadedFile $file, ?string $dataUrl, ?string $replacing = null): string
    {
        $bytes = $file ? (string) file_get_contents($file->getRealPath()) : $this->decodeDataUrl((string) $dataUrl);
        $png = $this->clean($bytes);

        $path = self::DIRECTORY.'/'.Str::uuid().'.png';
        Storage::disk(self::DISK)->put($path, $png);

        $this->delete($replacing);

        return $path;
    }

    /** A copy kept with a signed step or application. */
    public function snapshot(?string $path): ?string
    {
        if (! self::exists($path)) {
            return null;
        }

        $copy = self::SNAPSHOTS.'/'.Str::uuid().'.png';
        Storage::disk(self::DISK)->copy($path, $copy);

        return $copy;
    }

    /** Only live signatures; a snapshot is part of the signed record. */
    public function delete(?string $path): void
    {
        if ($path && str_starts_with($path, self::DIRECTORY.'/') && ! str_starts_with($path, self::SNAPSHOTS.'/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Removes a snapshot copy that was never attached to a signed record. */
    public function deleteSnapshot(?string $path): void
    {
        if ($path && str_starts_with($path, self::SNAPSHOTS.'/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function exists(?string $path): bool
    {
        return $path !== null && Storage::disk(self::DISK)->exists($path);
    }

    private function decodeDataUrl(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match)) {
            throw ValidationException::withMessages(['signature' => 'That signature could not be read. Draw it again.']);
        }

        $bytes = base64_decode($match[1], true);

        if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['signature' => 'That signature could not be read. Draw it again.']);
        }

        return $bytes;
    }

    /** Transparent background, cropped to the ink, ink colour unified, scaled down. */
    private function clean(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw ValidationException::withMessages(['signature' => 'That image could not be read. Try a PNG or JPG.', 'file' => 'That image could not be read. Try a PNG or JPG.']);
        }

        // Keep the pixel loop small: scan at most 1000 px on the long side.
        $long = max(imagesx($source), imagesy($source));
        if ($long > 1000) {
            $scaled = imagescale($source, (int) round(imagesx($source) * 1000 / $long), -1, IMG_BILINEAR_FIXED);
            imagedestroy($source);
            $source = $scaled;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ink = imagecreatetruecolor($width, $height);
        imagealphablending($ink, false);
        imagesavealpha($ink, true);
        imagefill($ink, 0, 0, imagecolorallocatealpha($ink, 0, 0, 0, 127));
        [$red, $green, $blue] = self::INK;

        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($source, $x, $y);
                $transparent = ($pixel >> 24) & 0x7F; // 0 opaque … 127 clear
                $luminance = (0.299 * (($pixel >> 16) & 0xFF)) + (0.587 * (($pixel >> 8) & 0xFF)) + (0.114 * ($pixel & 0xFF));
                $opacity = min(1.0, max(0.0, (255 - $luminance) * 1.4 / 255)) * (1 - $transparent / 127);

                if ($opacity < 0.08) {
                    continue;
                }

                imagesetpixel($ink, $x, $y, imagecolorallocatealpha($ink, $red, $green, $blue, 127 - (int) round($opacity * 127)));
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }
        imagedestroy($source);

        if ($maxX < 0) {
            imagedestroy($ink);
            throw ValidationException::withMessages(['signature' => 'No signature was found. Sign inside the box.', 'file' => 'No signature was found in that image.']);
        }

        $pad = 6;
        $cropped = imagecrop($ink, [
            'x' => max(0, $minX - $pad),
            'y' => max(0, $minY - $pad),
            'width' => min($width, $maxX + $pad + 1) - max(0, $minX - $pad),
            'height' => min($height, $maxY + $pad + 1) - max(0, $minY - $pad),
        ]);
        imagedestroy($ink);

        if ($cropped === false) {
            throw ValidationException::withMessages(['signature' => 'That signature could not be read. Draw it again.']);
        }

        if (imagesx($cropped) > self::MAX_WIDTH || imagesy($cropped) > self::MAX_HEIGHT) {
            $ratio = min(self::MAX_WIDTH / imagesx($cropped), self::MAX_HEIGHT / imagesy($cropped));
            $resized = imagescale($cropped, max(1, (int) round(imagesx($cropped) * $ratio)), max(1, (int) round(imagesy($cropped) * $ratio)), IMG_BILINEAR_FIXED);
            imagedestroy($cropped);
            $cropped = $resized;
        }

        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        ob_start();
        imagepng($cropped, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($cropped);

        return $png;
    }
}
