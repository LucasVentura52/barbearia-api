<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploader
{
    public static function store(UploadedFile $file, array $options = []): array
    {
        $directory = $options['directory'] ?? ('uploads/' . date('Y/m'));
        $maxDimension = (int) ($options['maxDimension'] ?? 1200);
        $quality = (int) ($options['quality'] ?? 90);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        $path = $directory . '/' . Str::random(40) . '.' . $extension;
        $image = self::createImage($file->getPathname(), $extension);

        if (!$image) {
            $path = $file->store($directory, 'public');

            return [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ];
        }

        $image = self::applyOrientation($image, $file->getPathname(), $extension);

        $width = imagesx($image);
        $height = imagesy($image);
        $maxCurrent = max($width, $height);

        if ($maxCurrent > $maxDimension) {
            $scale = $maxDimension / $maxCurrent;
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if (in_array($extension, ['png', 'webp'], true)) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }

            imagecopyresampled(
                $resized,
                $image,
                0,
                0,
                0,
                0,
                $newWidth,
                $newHeight,
                $width,
                $height
            );

            imagedestroy($image);
            $image = $resized;
        }

        $binary = self::encodeImage($image, $extension, $quality);
        imagedestroy($image);

        if ($binary === null) {
            $path = $file->store($directory, 'public');

            return [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ];
        }

        Storage::disk('public')->put($path, $binary);

        return [
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ];
    }

    private static function createImage(string $path, string $extension)
    {
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                return @imagecreatefromjpeg($path);
            case 'png':
                return @imagecreatefrompng($path);
            case 'webp':
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
            case 'gif':
                return @imagecreatefromgif($path);
            default:
                return null;
        }
    }

    private static function applyOrientation($image, string $path, string $extension)
    {
        if (!in_array($extension, ['jpg', 'jpeg'], true) || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? 1;

        if ($orientation === 3) {
            return imagerotate($image, 180, 0);
        }

        if ($orientation === 6) {
            return imagerotate($image, -90, 0);
        }

        if ($orientation === 8) {
            return imagerotate($image, 90, 0);
        }

        return $image;
    }

    private static function encodeImage($image, string $extension, int $quality): ?string
    {
        ob_start();

        $result = true;
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                $result = imagejpeg($image, null, $quality);
                break;
            case 'png':
                $compression = max(0, min(9, (int) round((100 - $quality) / 10)));
                $result = imagepng($image, null, $compression);
                break;
            case 'webp':
                if (!function_exists('imagewebp')) {
                    $result = false;
                    break;
                }
                $result = imagewebp($image, null, $quality);
                break;
            case 'gif':
                $result = imagegif($image);
                break;
            default:
                $result = false;
                break;
        }

        $binary = ob_get_clean();

        return $result ? $binary : null;
    }
}
