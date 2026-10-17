<?php

/**
 * VISTARO business configuration. Everything here is overridable via .env
 * so behaviour (limits, TTLs, providers) never needs a code change.
 */
return [

    'pagination' => [
        'default' => (int) env('PAGINATION_DEFAULT', 10),
        'max'     => (int) env('PAGINATION_MAX', 100),   // hard ceiling, enforced by validation
    ],

    'stock' => [
        'low_threshold'     => (int) env('STOCK_LOW_THRESHOLD', 5),   // admin warning: available < 5
        'show_exact_up_to'  => (int) env('STOCK_SHOW_EXACT_UP_TO', 10), // storefront "8 left" when <= 10
    ],

    'otp' => [
        'length'               => 6,
        'ttl_seconds'          => (int) env('OTP_TTL_SECONDS', 300),
        'max_issuances'        => 3,            // per mobile per window; the first OTP counts as 1
        'window_seconds'       => 86400,        // rolling daily window anchored at first issuance
        'cooldowns'            => [1 => 30, 2 => 60], // seconds to wait after issuance #1 and #2
        'max_verify_attempts'  => (int) env('OTP_MAX_VERIFY_ATTEMPTS', 5),
        // TESTING ONLY: opt in on the private test deployment to use one predictable OTP and skip delivery/logging.
        'fixed_enabled'        => filter_var(env('OTP_FIXED_ENABLED', false), FILTER_VALIDATE_BOOL),
        'fixed_code'            => (string) env('OTP_FIXED_CODE', '637485'),
        'delivery'             => env('OTP_DELIVERY', 'log'), // log (local/testing only) | sms (not implemented yet)
    ],

    'auth' => [
        'default_country_code' => env('DEFAULT_COUNTRY_CODE', '91'), // prepended to 10-digit national numbers
        'admin_password_min'   => 10,
    ],

    'tokens' => [
        // Absolute lifetime of a bearer token, in minutes.
        'customer_ttl_minutes' => (int) env('CUSTOMER_TOKEN_TTL_MINUTES', 60 * 24 * 30),
        'admin_ttl_minutes'    => (int) env('ADMIN_TOKEN_TTL_MINUTES', 60 * 8),
        'max_customer_tokens'  => 5,   // oldest are revoked beyond this
    ],

    'shipping' => [
        'provider'       => env('DELIVERY_PROVIDER', 'mock'),
        'free_above'     => (int) env('SHIPPING_FREE_ABOVE', 1999),
        'flat_fee'       => (int) env('SHIPPING_FLAT_FEE', 99),
        'webhook_secret' => env('DELIVERY_WEBHOOK_SECRET'),
    ],

    'payments' => [
        'gateway' => env('PAYMENT_GATEWAY', 'mock'),
        'methods' => ['cod', 'upi', 'card'],
    ],

    'images' => [
        'disk'      => env('IMAGES_DISK', env('FILESYSTEM_DISK', 'public')),
        'max_kb'    => 5120,
        'mimes'     => ['jpg', 'jpeg', 'png', 'webp'],
        'max_per_product' => 10,
    ],

    'catalog' => [
        'sale_min_discount' => (int) env('SALE_MIN_DISCOUNT', 25),   // storefront "sale" badge = discount >= 25% (frontend rule)
        'related_limit'     => 8,
        'max_attributes'    => 20,
    ],

    'cart' => [
        'max_line_quantity' => 10,
    ],

    'addresses' => [
        'max_per_customer' => (int) env('MAX_ADDRESSES', 10),       // defensive cap, not a business rule from the spec
    ],

    'orders' => [
        'return_window_days'    => (int) env('RETURN_WINDOW_DAYS', 14), // frontend policy text ("within 14 days of delivery")
        'idempotency_ttl_hours' => 24,
    ],

    'cache' => [
        'catalog_ttl' => (int) env('CATALOG_CACHE_TTL', 300),
    ],

    // business_settings (dynamic, DB-backed). The shipping / return values above stay as the DEFAULTS used when a setting row
    // is absent or invalid; once a row exists it wins. Writes flush the cache, so TTL only bounds cross-process staleness.
    'settings' => [
        'cache_ttl' => (int) env('SETTINGS_CACHE_TTL', 300),
    ],
];
