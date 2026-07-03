<?php

namespace App\Services;

use App\Models\Person;
use App\Models\PersonPhoto;
use Illuminate\Support\Facades\Storage;

class ImageThumbnailService
{
    public function ensureForPhoto(PersonPhoto $photo): ?string
    {
        if (! $photo->path || ! Storage::disk('public')->exists($photo->path)) {
            return null;
        }
        if ($photo->thumbnail_path && Storage::disk('public')->exists($photo->thumbnail_path)) {
            return $photo->thumbnail_path;
        }

        $target = $this->create($photo->path);
        if ($target) {
            $photo->updateQuietly([
                'thumbnail_path' => $target,
                'thumbnail_file_size' => Storage::disk('public')->size($target),
            ]);
        }

        return $target;
    }

    public function ensureForPerson(Person $person): ?string
    {
        if (! $person->photo_path || ! Storage::disk('public')->exists($person->photo_path)) {
            return null;
        }
        if ($person->photo_thumbnail_path && Storage::disk('public')->exists($person->photo_thumbnail_path)) {
            return $person->photo_thumbnail_path;
        }

        $target = $this->create($person->photo_path);
        if ($target) {
            $person->updateQuietly(['photo_thumbnail_path' => $target]);
        }

        return $target;
    }

    private function create(string $sourcePath, int $maxSize = 480): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $disk = Storage::disk('public');
        $absolute = $disk->path($sourcePath);
        $contents = @file_get_contents($absolute);
        if ($contents === false) {
            return null;
        }
        $source = @imagecreatefromstring($contents);
        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width < 1 || $height < 1) {
            imagedestroy($source);

            return null;
        }

        $scale = min(1, $maxSize / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
        imagefilledrectangle($thumbnail, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        $directory = trim(dirname($sourcePath), '.');
        $filename = pathinfo($sourcePath, PATHINFO_FILENAME).'.thumb.webp';
        $targetPath = ($directory ? $directory.'/' : '').$filename;
        if ($directory !== '') {
            $disk->makeDirectory($directory);
        }
        $written = function_exists('imagewebp')
            ? imagewebp($thumbnail, $disk->path($targetPath), 78)
            : false;

        if (! $written) {
            $targetPath = ($directory ? $directory.'/' : '')
                .pathinfo($sourcePath, PATHINFO_FILENAME).'.thumb.jpg';
            $background = imagecreatetruecolor($targetWidth, $targetHeight);
            $white = imagecolorallocate($background, 255, 255, 255);
            imagefill($background, 0, 0, $white);
            imagecopy($background, $thumbnail, 0, 0, 0, 0, $targetWidth, $targetHeight);
            $written = imagejpeg($background, $disk->path($targetPath), 82);
            imagedestroy($background);
        }

        imagedestroy($source);
        imagedestroy($thumbnail);

        return $written ? $targetPath : null;
    }
}
