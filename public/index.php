<?php

declare(strict_types=1);

// Find autoloader.
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../autoload.php',
];

$autoloaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require $path;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Composer autoloader not found. Run composer install.']);
    exit(1);
}

$kernel = new Waaseyaa\Foundation\Kernel\HttpKernel(dirname(__DIR__));
$kernel->handle();
