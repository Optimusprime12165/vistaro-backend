<?php

namespace App\Support;

use App\Enums\Size;

/**
 * Sizes are dynamic: any short code an admin defines (S, XL, 28, FREE SIZE ...).
 * Codes are stored trimmed, with single spaces, in UPPER CASE so "xl" and "XL" can never become two stock rows.
 * The App\Enums\Size list (XXXS..4XL) is only used as the preferred DISPLAY ORDER and as the default preset in the admin UI.
 */
final class SizeCode
{
    /** letters/digits first, then letters, digits, space . + - ; at most 16 characters */
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 .+\-]{0,15}$/';

    public static function normalize(?string $code): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $code)));
    }

    /** @return list<string> validation rules for ONE already-normalised size code */
    public static function rules(): array
    {
        return ['string', 'max:16', 'regex:' . self::PATTERN];
    }

    /** Sort key: known sizes in XXXS..4XL order, then numeric sizes ascending, then everything else alphabetically. */
    public static function sortKey(string $code): string
    {
        $code = self::normalize($code);
        $known = Size::tryFrom($code);

        if ($known !== null) {
            return sprintf('0|%03d|', $known->order());
        }
        if (is_numeric($code)) {
            return sprintf('1|%012.2f|', (float) $code);
        }

        return '2|' . $code;
    }
}
