<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;

$projectRoot = $argv[1] ?? '';
$secret = $argv[2] ?? '';
require $projectRoot . '/vendor/autoload.php';

putenv('APP_ENV=production');
putenv('APP_DEBUG=0');
putenv('WAASEYAA_APP_SECRET=' . $secret);
putenv('WAASEYAA_DB=' . $projectRoot . '/storage/waaseyaa.sqlite');
$_ENV['APP_ENV'] = 'production';
$_ENV['APP_DEBUG'] = '0';
$_ENV['WAASEYAA_APP_SECRET'] = $secret;
$_ENV['WAASEYAA_DB'] = $projectRoot . '/storage/waaseyaa.sqlite';

try {
    new \ReflectionMethod(AbstractKernel::class, 'boot')->invoke(new HttpKernel($projectRoot));
    echo json_encode(['booted' => true, 'error' => ''], JSON_THROW_ON_ERROR);
} catch (\Throwable $exception) {
    echo json_encode(['booted' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
