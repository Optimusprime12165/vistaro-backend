<?php

namespace App\Contracts;

use App\DTOs\Storage\StoredImage;
use Illuminate\Http\UploadedFile;

interface ImageStorageInterface
{
    public function store(UploadedFile $file, string $directory): StoredImage;

    public function delete(string $disk, string $path): void;

    public function url(string $disk, string $path): string;
}
