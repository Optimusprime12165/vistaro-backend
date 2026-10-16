<?php

namespace App\Support;

use App\Enums\Size;
use App\Services\Settings\BusinessSettingsService;

/**
 * Which sizes the store currently offers.
 *
 * The master list lives in the business setting `catalog.sizes` (type json, public), e.g. ["XXXS","XXS","XS","S","M","L","XL","XXL","3XL","4XL"].
 * Admins edit it in Business Settings; the product editor only offers those sizes and the admin APIs that ADD a size to a product
 * (product create, PUT /products/{id}/sizes) validate against it. Existing product sizes keep working for stock, cart and orders
 * even if a size is later removed from the list. Only sizes the system knows (App\Enums\Size) can be enabled.
 */
final class SizeCatalog
{
    public const KEY = 'catalog.sizes';

    /** @return list<string> enabled sizes in canonical order (XXXS ... 4XL); falls back to every supported size if the setting is absent/invalid */
    public static function enabled(): array
    {
        $known = Size::values();

        try {
            $stored = app(BusinessSettingsService::class)->get(self::KEY);
        } catch (\Throwable) {
            return $known;
        }
        if (! is_array($stored) || $stored === []) {
            return $known;
        }

        $wanted = array_map('strval', $stored);
        $list = array_values(array_filter($known, fn (string $s) => in_array($s, $wanted, true)));   // canonical order, unknown codes dropped

        return $list === [] ? $known : $list;
    }
}
