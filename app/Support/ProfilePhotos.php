<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores account profile photos (the legacy profile picture upload).
 * Every upload is decoded and re-encoded with GD as a centered square
 * 400×400 JPEG, so only clean pixels are kept: no original file, no EXIF
 * (e.g. GPS location), and nothing a crafted file could smuggle through.
 * The legacy page cropped in the browser; a centered crop needs no extra
 * JavaScript library.
 */
class ProfilePhotos
{
    public const DIRECTORY = 'profile-photos';

    private const SIZE = 400;

    public function store(UploadedFile $file, ?string $replacing = null): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw ValidationException::withMessages(['profile_photo' => 'That image could not be read. Try a JPG or PNG.']);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);

        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        // White behind any transparency (PNG/WebP) instead of black.
        imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled(
            $square, $image,
            0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE, $side, $side,
        );

        ob_start();
        imagejpeg($square, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($square);

        $path = self::DIRECTORY.'/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        $this->delete($replacing);

        return $path;
    }

    public function delete(?string $path): void
    {
        // Only ever touch files inside our own directory.
        if ($path && str_starts_with($path, self::DIRECTORY.'/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
