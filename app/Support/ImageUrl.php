<?php

namespace App\Support;

use App\Contracts\ImageStorageInterface;

/** Turns a stored image reference into a URL: absolute URLs / the 'external' disk pass through, everything else uses the storage disk. */
final class ImageUrl
{
    public static function resolve(?string $path, ?string $disk = null): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if ($disk === 'external' || preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return app(ImageStorageInterface::class)->url($disk ?? (string) config('vistaro.images.disk'), $path);
    }
}
