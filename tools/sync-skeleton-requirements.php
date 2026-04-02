<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/sync-skeleton-requirements.php <source-composer.json> <target-composer.json>\n");
    exit(1);
}

[$_, $sourcePath, $targetPath] = $argv;

/** @return array<string, mixed> */
function loadComposerJson(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException(sprintf('Composer file not found: %s', $path));
    }

    /** @var array<string, mixed> $data */
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $data;
}

/**
 * @param array<string, mixed> $source
 * @param array<string, mixed> $target
 * @return array<string, mixed>
 */
function syncSkeletonRequirements(array $source, array $target): array
{
    $sourceRequire = is_array($source['require'] ?? null) ? $source['require'] : [];
    $targetRequire = is_array($target['require'] ?? null) ? $target['require'] : [];

    $nextRequire = [];

    foreach ($targetRequire as $package => $constraint) {
        if (!is_string($package) || str_starts_with($package, 'waaseyaa/')) {
            continue;
        }

        $nextRequire[$package] = $constraint;
    }

    foreach ($sourceRequire as $package => $constraint) {
        if (!is_string($package) || !str_starts_with($package, 'waaseyaa/')) {
            continue;
        }

        $nextRequire[$package] = $constraint;
    }

    ksort($nextRequire);

    $target['require'] = $nextRequire;

    return $target;
}

try {
    $source = loadComposerJson($sourcePath);
    $target = loadComposerJson($targetPath);
    $updated = syncSkeletonRequirements($source, $target);

    file_put_contents(
        $targetPath,
        json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
