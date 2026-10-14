<?php

namespace App\Support;

/** Single source of the stock thresholds: admin warning (< low_threshold) and storefront "N left" (<= show_exact_up_to). */
final class StockDisplay
{
    /** Admin: out (0) | low (< 5) | ok */
    public static function adminStatus(int $qty): string
    {
        return $qty <= 0 ? 'out' : ($qty < (int) config('vistaro.stock.low_threshold') ? 'low' : 'ok');
    }

    /** Storefront: exact number only when 1..10 units remain; never reveal larger quantities. */
    public static function storefrontLeft(int $qty): ?int
    {
        return $qty > 0 && $qty <= (int) config('vistaro.stock.show_exact_up_to') ? $qty : null;
    }

    public static function storefrontLabel(int $qty): ?string
    {
        $left = self::storefrontLeft($qty);

        return $left === null ? null : "{$left} left";
    }
}
