<?php

declare(strict_types=1);

// Resolution only: no class registration, reflection or framework bootstrap.
// Usage: php a0-autoload-probe.php <source> <public-surface.json> <ClassLoader.php>
if ($argc !== 4) {
    throw new InvalidArgumentException('Expected source checkout, public-surface JSON, Composer ClassLoader.php');
}
require $argv[3];
$source = realpath($argv[1]);
if ($source === false) {
    throw new InvalidArgumentException('Source checkout is missing');
}
$public = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$rows = [];
foreach ($public as $entry) {
    if (!$entry['declared_outside_src']) {
        continue;
    }
    $directory = explode('/', $entry['source_candidates'][0])[1];
    $root = $source . '/packages/' . $directory;
    $manifest = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $resolve = static function (array $sections) use ($root, $manifest, $entry): string|false {
        $loader = new Composer\Autoload\ClassLoader();
        foreach ($sections as $section) {
            foreach ($manifest[$section]['psr-4'] ?? [] as $namespace => $paths) {
                $loader->addPsr4($namespace, array_map(
                    static fn (string $path): string => $root . '/' . $path,
                    (array) $paths,
                ));
            }
        }
        return $loader->findFile($entry['symbol']);
    };
    $rows[] = [
        'symbol' => $entry['symbol'],
        'declarations' => $entry['source_candidates'],
        'runtime_psr4_resolves' => $resolve(['autoload']) !== false,
        'package_root_dev_psr4_resolves' => $resolve(['autoload', 'autoload-dev']) !== false,
        'scope' => 'isolated package PSR-4 resolution; not a split-package install or class-load test',
    ];
}
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
