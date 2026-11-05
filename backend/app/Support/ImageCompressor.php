<?php

namespace App\Support;

use GdImage;

/**
 * Makes product photos small enough to load quickly: at most MAX_SIDE pixels on the longest
 * side, saved as WebP. Saving the picture again also leaves out what cameras hide in a photo,
 * such as the place it was taken.
 */
final class ImageCompressor
{
    public const MAX_SIDE = 1600;

    /** The largest photo accepted, on either side, so opening one never runs out of memory. */
    public const MAX_INPUT_SIDE = 6000;

    public const QUALITY = 80;

    /** Opening a photo takes about 4 bytes a pixel: 144 MB for one of 6000 × 6000. */
    private const MEMORY = '256M';

    /**
     * The photo at $path, made smaller, as WebP. Null when it should be kept as it is: when it
     * is already a WebP no larger than MAX_SIDE (the app shrinks photos like that in the browser
     * before uploading, and doing it twice would only lose quality), or when it cannot be opened.
     */
    public static function compress(string $path): ?string
    {
        $size = @getimagesize($path);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        [$width, $height, $type] = $size;
        $scale = min(1, self::MAX_SIDE / max($width, $height));

        if ($type === IMAGETYPE_WEBP && $scale >= 1) {
            return null;
        }

        self::makeRoom();

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if ($image === false) {
            return null;
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = self::turnUpright($image, $path);
        }

        imagepalettetotruecolor($image);
        $image = self::resize($image, $scale);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, self::QUALITY);

        return ob_get_clean() ?: null;
    }

    /**
     * Phone cameras often save a photo sideways, with a note saying which way is up. That note
     * is left out of the smaller copy, so turn the picture itself the right way up first.
     */
    private static function turnUpright(GdImage $image, string $path): GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $flip = match ($orientation) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => null,
        };

        if ($flip !== null) {
            imageflip($image, $flip);
        }

        // Degrees anticlockwise.
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };

        return $angle ? (imagerotate($image, $angle, 0) ?: $image) : $image;
    }

    private static function resize(GdImage $image, float $scale): GdImage
    {
        if ($scale >= 1) {
            return $image;
        }

        $width = max(1, (int) round(imagesx($image) * $scale));
        $height = max(1, (int) round(imagesy($image) * $scale));
        $resized = imagecreatetruecolor($width, $height);

        // Keep see-through backgrounds see-through.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $resized;
    }

    private static function makeRoom(): void
    {
        $limit = (string) ini_get('memory_limit');

        if ($limit !== '-1' && ini_parse_quantity($limit) < ini_parse_quantity(self::MEMORY)) {
            ini_set('memory_limit', self::MEMORY);
        }
    }
}
