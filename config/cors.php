<?php
return [
    'paths' => ['api/*'],

    'allowed_methods' => [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
    ],

    'allowed_origins' => array_values(
        array_filter(
            array_map(
                'trim',
                explode(
                    ',',
                    (string) env(
                        'FRONTEND_URL',
                        'http://localhost:3000,https://accipitral-chlamydate-leo.ngrok-free.dev'
                    )
                )
            )
        )
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'Idempotency-Key',
        'X-Requested-With',
    ],

    'exposed_headers' => [
        'Retry-After',
        'Idempotent-Replayed',
        'X-Mock-Shipment',
    ],

    'max_age' => 600,

    'supports_credentials' => false,
];
