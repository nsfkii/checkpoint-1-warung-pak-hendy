<?php

return [
    'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),
    'lease_seconds' => 60,
    'max_attempts' => 5,
    'retry_base_seconds' => 5,
    'retry_max_seconds' => 900,
    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'connection_timeout' => (float) env('RABBITMQ_CONNECTION_TIMEOUT', 3),
        'read_write_timeout' => (float) env('RABBITMQ_READ_WRITE_TIMEOUT', 10),
        'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 5),
        'publisher_confirm_timeout' => (float) env('RABBITMQ_PUBLISH_CONFIRM_TIMEOUT', 5),
    ],
];
