<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache for PUBLIC catalogue payloads only (never customer data, never prices used for checkout:
 * cart, checkout preview and order creation always recompute from the database).
 * Any change to a category, sub-category or product flushes it (see VistaroServiceProvider::boot).
 */
final class CatalogCache
{
    private const VERSION_KEY = 'catalog:version';

    public static function ttl(): int
    {
        return (int) config('vistaro.cache.catalog_public_ttl', 60);
    }

    /** @template T @param callable():T $fn @return T */
    public static function remember(string $name, callable $fn): mixed
    {
        $ttl = self::ttl();
        if ($ttl <= 0) {
            return $fn();
        }

        $version = (int) Cache::get(self::VERSION_KEY, 1);

        return Cache::remember("catalog:v{$version}:{$name}", $ttl, $fn);
    }

    public static function flush(): void
    {
        // Bumping the version orphans every cached entry at once (works on every cache driver, no tags needed).
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 1) + 1);
    }
}
