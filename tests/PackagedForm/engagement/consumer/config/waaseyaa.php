<?php

declare(strict_types=1);
return [
    'environment' => getenv('APP_ENV') ?: 'production',
    'database' => getenv('WAASEYAA_DB') ?: null,
    'engagement' => [
        'installation_id' => getenv('ENGAGEMENT_QUOTA_PROFILE') === 'defaults'
            ? 'synthetic-engagement-defaults-v1'
            : 'synthetic-engagement-consumer-v1',
        'storage_backend' => getenv('ENGAGEMENT_LAYOUT') ?: 'sql-blob',
        'limits' => getenv('ENGAGEMENT_QUOTA_PROFILE') === 'defaults' ? [] : [
            'comment' => ['minute' => 10000, 'hour' => 100000],
            'edit' => ['minute' => 10000, 'hour' => 100000],
            'like' => ['minute' => 10000, 'hour' => 100000],
            'key' => ['minute' => 10000, 'hour' => 100000],
        ],
    ],
];
