<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
Waaseyaa\Foundation\Kernel\EnvLoader::load(dirname(__DIR__) . '/.env');

header('Content-Type: application/json');
echo json_encode([
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'sqlite' => SQLite3::version(),
    'environment' => getenv('APP_ENV'),
    'debug' => getenv('APP_DEBUG'),
    'fallback' => getenv('WAASEYAA_DEV_FALLBACK_ACCOUNT'),
    'secret_present' => getenv('WAASEYAA_APP_SECRET') !== false && getenv('WAASEYAA_APP_SECRET') !== '',
    'phpunit_present' => class_exists(PHPUnit\Framework\TestCase::class),
], JSON_THROW_ON_ERROR);
