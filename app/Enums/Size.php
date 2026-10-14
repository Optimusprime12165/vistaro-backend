<?php

namespace App\Enums;

enum Size: string
{
    case XS = 'XS';
    case S = 'S';
    case M = 'M';
    case L = 'L';
    case XL = 'XL';
    case XXL = 'XXL';
    case XXXL = '3XL';
    case XXXXL = '4XL';

    /** @return list<string> in display order */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /** Position used for sorting size chips XS → 4XL. */
    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }
}
