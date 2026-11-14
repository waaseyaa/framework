<?php

declare(strict_types=1);

return [
    'environment' => getenv('APP_ENV') ?: 'production',
    'database' => getenv('WAASEYAA_DB') ?: null,
];
