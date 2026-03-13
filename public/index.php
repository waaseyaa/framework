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
try {
    $kernel->handle();
} catch (\Throwable $e) {
    error_log(sprintf('[Waaseyaa] Unhandled top-level exception: %s in %s:%d%s', $e->getMessage(), $e->getFile(), $e->getLine(), PHP_EOL . $e->getTraceAsString()));
    http_response_code(500);
    header('Content-Type: application/vnd.api+json');
    echo json_encode([
        'jsonapi' => ['version' => '1.1'],
        'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'An unexpected error occurred.']],
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
