<?php

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "Usage: prepare.php configure|bind-lock|snapshot <framework-root> <consumer-root>\n");
    exit(2);
}

[$script, $operation, $frameworkRoot, $consumerRoot] = $argv;
$frameworkRoot = realpath($frameworkRoot) ?: '';
$consumerRoot = realpath($consumerRoot) ?: '';
if ($frameworkRoot === '' || $consumerRoot === '') {
    throw new RuntimeException('Reference-consumer roots must resolve.');
}

if ($operation === 'configure') {
    $packageVersions = [];
    $packageComposers = glob($frameworkRoot . '/packages/*/composer.json');
    if ($packageComposers === false) {
        throw new RuntimeException('Unable to enumerate candidate package manifests.');
    }
    foreach ($packageComposers as $packageComposer) {
        $package = json_decode((string) file_get_contents($packageComposer), true, 512, JSON_THROW_ON_ERROR);
        $name = $package['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new RuntimeException("Candidate package has no Composer name: {$packageComposer}");
        }
        $packageVersions[$name] = 'dev-main';
    }
    ksort($packageVersions, SORT_STRING);

    $path = $consumerRoot . '/composer.json';
    $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $composer['repositories'] = [
        [
            'type' => 'path',
            'url' => $frameworkRoot,
            'options' => ['symlink' => false, 'versions' => ['waaseyaa/framework' => 'dev-main']],
        ],
        [
            'type' => 'path',
            'url' => $frameworkRoot . '/packages/*',
            'options' => ['symlink' => false, 'versions' => $packageVersions],
        ],
    ];
    $composer['require']['waaseyaa/framework'] = 'dev-main';
    file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

if ($operation === 'bind-lock') {
    $answers = $consumerRoot . '/site.answers.yaml';
    $lock = $consumerRoot . '/composer.lock';
    $bytes = (string) file_get_contents($answers);
    $bytes = preg_replace(
        '/observed_lock_sha256:\s*[a-f0-9]{64}/',
        'observed_lock_sha256: ' . hash_file('sha256', $lock),
        $bytes,
        1,
        $count,
    );
    if ($count !== 1 || !is_string($bytes)) {
        throw new RuntimeException('Unable to bind the reference manifest to composer.lock.');
    }
    file_put_contents($answers, $bytes);
    exit(0);
}

if ($operation === 'snapshot') {
    $metadata = json_decode((string) file_get_contents($consumerRoot . '/.waaseyaa/generated.json'), true, 512, JSON_THROW_ON_ERROR);
    $rows = [];
    foreach ($metadata['artifacts'] ?? [] as $artifact) {
        $path = (string) ($artifact['path'] ?? '');
        $absolute = $consumerRoot . '/' . $path;
        if ($path === '' || !is_file($absolute)) {
            throw new RuntimeException("Generated artifact is absent: {$path}");
        }
        $rows[] = [$path, sprintf('%04o', fileperms($absolute) & 0o777), hash_file('sha256', $absolute)];
    }
    sort($rows, SORT_REGULAR);
    echo hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), "\n";
    exit(0);
}

throw new InvalidArgumentException("Unknown reference-consumer operation: {$operation}");
