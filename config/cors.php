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
                        'https://vistaro-qosn.onrender.com'
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
        'ngrok-skip-browser-warning',   // sent by the frontend when the API is served through a free ngrok tunnel
    ],

    'exposed_headers' => [
        'Retry-After',
        'Idempotent-Replayed',
        'X-Mock-Shipment',
    ],

    'max_age' => 600,

    'supports_credentials' => false,
];
