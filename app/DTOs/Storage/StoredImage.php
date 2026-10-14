<?php

namespace App\DTOs\Storage;

final class StoredImage
{
    public function __construct(public readonly string $disk, public readonly string $path)
    {
    }
}
