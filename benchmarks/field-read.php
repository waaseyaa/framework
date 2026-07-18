<?php

declare(strict_types=1);

use Waaseyaa\Benchmarks\FieldReadBenchmark;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/FieldReadBenchmark.php';

$iterations = max(1, (int) ($argv[1] ?? 100_000));
$samples = max(3, (int) ($argv[2] ?? 7));
if ($samples % 2 === 0) {
    ++$samples;
}

echo json_encode(
    FieldReadBenchmark::run($iterations, $samples),
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
).PHP_EOL;
