<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$kernel = new \Waaseyaa\Foundation\Kernel\HttpKernel(dirname(__DIR__));
$kernel->handle();
