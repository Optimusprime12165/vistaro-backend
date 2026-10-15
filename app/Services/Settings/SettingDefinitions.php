<?php

namespace App\Services\Settings;

use App\Enums\SettingType;

/** Registry of the settings that the application logic consumes today (shipping fee, free-shipping threshold, return window). */
final class SettingDefinitions
{
    public const SHIPPING_FLAT_FEE = 'shipping.flat_fee';
    public const SHIPPING_FREE_ABOVE = 'shipping.free_above';
    public const RETURN_WINDOW_DAYS = 'orders.return_window_days';

    /** @var array<string,SettingDefinition>|null */
    private static ?array $all = null;

    /** @return array<string,SettingDefinition> */
    public static function all(): array
    {
        return self::$all ??= [
            self::SHIPPING_FLAT_FEE => new SettingDefinition(
                self::SHIPPING_FLAT_FEE, SettingType::Integer, 'shipping',
                'Shipping charge in whole rupees, applied when the cart subtotal is below the free-shipping threshold.',
                'vistaro.shipping.flat_fee', 99, 0, 100_000,
            ),
            self::SHIPPING_FREE_ABOVE => new SettingDefinition(
                self::SHIPPING_FREE_ABOVE, SettingType::Integer, 'shipping',
                'Cart subtotal (whole rupees) at or above which shipping is free. 0 means shipping is always free.',
                'vistaro.shipping.free_above', 1999, 0, 10_000_000,
            ),
            self::RETURN_WINDOW_DAYS => new SettingDefinition(
                self::RETURN_WINDOW_DAYS, SettingType::Integer, 'orders',
                'Number of days after delivery during which a customer may request a return.',
                'vistaro.orders.return_window_days', 14, 0, 365,
            ),
        ];
    }

    public static function find(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }
}
