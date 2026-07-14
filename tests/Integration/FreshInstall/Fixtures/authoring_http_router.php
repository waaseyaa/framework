<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Kernel\HttpKernel;

$projectRoot = getenv('WAASEYAA_TEST_PROJECT_ROOT');
if (!is_string($projectRoot) || $projectRoot === '') {
    throw new RuntimeException('Missing WAASEYAA_TEST_PROJECT_ROOT.');
}

require $projectRoot . '/vendor/autoload.php';

new HttpKernel($projectRoot)->handle()->send();
