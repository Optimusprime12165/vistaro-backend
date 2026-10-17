<?php

namespace App\Support;

/** One canonical form for an email (trimmed, lower-cased) so the OTP limits and lookups cannot be dodged by letter case. */
final class EmailAddress
{
    public static function normalize(?string $raw): ?string
    {
        $s = mb_strtolower(trim((string) $raw));

        return $s === '' ? null : $s;
    }

    /** jo***@gmail.com : safe for logs and API responses. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $keep . str_repeat('*', max(3, mb_strlen($local) - mb_strlen($keep))) . ($domain !== '' ? '@' . $domain : '');
    }
}
