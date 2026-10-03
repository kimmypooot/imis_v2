<?php

namespace App\Support\HR\Pds;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The PDS photo (passport size, 3.5 × 4.5 cm), on the private disk — it
 * is personal data, served only through an authorized route. Like
 * ProfilePhotos, every upload is decoded and re-encoded with GD as a
 * centered 7:9 crop, so no original file or EXIF (e.g. GPS) is kept.
 *
 * A signed submission keeps its own copy (copyForSubmission), so later
 * photo changes never alter what was signed.
 */
class PdsPhotos
{
    public const DISK = 'local';

    private const DIRECTORY = 'pds/photos';

    private const SUBMISSIONS = 'pds/submissions';

    // 3.5 × 4.5 cm at 300 dpi.
    private const WIDTH = 413;

    private const HEIGHT = 531;

    public function store(UploadedFile $file, ?string $replacing = null): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw ValidationException::withMessages(['photo' => 'That image could not be read. Try a JPG or PNG.']);
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // The largest centered 7:9 area of the source.
        $cropWidth = min($width, (int) round($height * self::WIDTH / self::HEIGHT));
        $cropHeight = (int) round($cropWidth * self::HEIGHT / self::WIDTH);

        $photo = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($photo, 0, 0, imagecolorallocate($photo, 255, 255, 255));
        imagecopyresampled(
            $photo, $image,
            0, 0, intdiv($width - $cropWidth, 2), intdiv($height - $cropHeight, 2),
            self::WIDTH, self::HEIGHT, $cropWidth, $cropHeight,
        );

        ob_start();
        imagejpeg($photo, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($photo);

        $path = self::DIRECTORY.'/'.Str::uuid().'.jpg';
        Storage::disk(self::DISK)->put($path, $jpeg);

        $this->delete($replacing);

        return $path;
    }

    public function copyForSubmission(?string $path): ?string
    {
        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $copy = self::SUBMISSIONS.'/'.Str::uuid().'.jpg';
        Storage::disk(self::DISK)->copy($path, $copy);

        return $copy;
    }

    public function delete(?string $path): void
    {
        // Only live photos; a submission's copy is part of the record.
        if ($path && str_starts_with($path, self::DIRECTORY.'/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function exists(?string $path): bool
    {
        return $path !== null && Storage::disk(self::DISK)->exists($path);
    }
}
