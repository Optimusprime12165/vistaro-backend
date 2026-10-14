<?php

namespace App\Services\Storage;

use App\Contracts\ImageStorageInterface;
use App\DTOs\Storage\StoredImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores images on any Laravel filesystem disk. `local`/`public` in development, `s3` in AWS:
 * switching is only IMAGES_DISK in .env, no service code changes.
 * Validation (mimes/size) happens in the FormRequest; here the file name and extension are
 * generated server-side from the detected MIME, never from the client-supplied name.
 */
final class DiskImageStorage implements ImageStorageInterface
{
    public function __construct(private readonly string $defaultDisk)
    {
    }

    public function store(UploadedFile $file, string $directory): StoredImage
    {
        $ext = $file->guessExtension() ?: 'jpg';
        $path = trim($directory, '/') . '/' . date('Y/m') . '/' . Str::uuid() . '.' . $ext;

        $ok = Storage::disk($this->defaultDisk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'public'],
        );

        if ($ok === false) {
            throw new RuntimeException('Image could not be stored.');
        }

        return new StoredImage($this->defaultDisk, $path);
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    /** Public URL. If the bucket is private later, switch to temporaryUrl() here only. */
    public function url(string $disk, string $path): string
    {
        return Storage::disk($disk)->url($path);
    }
}
