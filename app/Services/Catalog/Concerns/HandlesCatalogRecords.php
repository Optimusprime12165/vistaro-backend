<?php

namespace App\Services\Catalog\Concerns;

use App\Contracts\ImageStorageInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Slug generation and category-style image handling shared by the catalogue CRUD services. */
trait HandlesCatalogRecords
{
    /** @param class-string<Model> $modelClass */
    protected function uniqueSlug(string $base, string $modelClass, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base) ?: 'item';
        $candidate = $slug;

        for ($i = 2; $modelClass::query()->where('slug', $candidate)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $candidate = "{$slug}-{$i}";
        }

        return $candidate;
    }

    /**
     * Turns `image` (upload) / `image_url` (https link) into `image_path`. Returns [data, pathToDeleteAfterSuccess].
     *
     * @param array<string,mixed> $data
     * @return array{0:array<string,mixed>,1:?string}
     */
    protected function applyImage(array $data, ?string $oldPath, ImageStorageInterface $storage, string $directory): array
    {
        $replace = null;

        if (($data['image'] ?? null) instanceof UploadedFile) {
            $data['image_path'] = $storage->store($data['image'], $directory)->path;
            $replace = $oldPath;
        } elseif (array_key_exists('image_url', $data)) {
            $data['image_path'] = $data['image_url'];                  // null clears it
            $replace = $oldPath;
        }

        unset($data['image'], $data['image_url']);

        return [$data, $replace];
    }

    protected function deleteStoredImage(?string $path, ImageStorageInterface $storage): void
    {
        if ($path !== null && ! preg_match('#^https?://#i', $path)) {
            $storage->delete((string) config('vistaro.images.disk'), $path);
        }
    }
}
