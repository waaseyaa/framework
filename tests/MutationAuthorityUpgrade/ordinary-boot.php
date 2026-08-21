<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Foundation\Kernel\HttpKernel;

try {
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);
    fwrite(STDOUT, "ordinary boot OK\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
