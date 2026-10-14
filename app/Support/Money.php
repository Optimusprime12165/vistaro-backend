<?php

namespace App\Support;

/** Exact money arithmetic on integer minor units (paise). No floats anywhere in price/total calculations. */
final class Money
{
    /** "1999.50" | 1999 | "999.00" => 199950 */
    public static function toMinor(string|int $amount): int
    {
        $s = (string) $amount;
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?\d*$/', $s, $m)) {
            throw new \InvalidArgumentException("Invalid money value: {$s}");
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function format(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
