<?php

declare(strict_types=1);

if ($argc !== 3 && $argc !== 4) {
    fwrite(STDERR, "Usage: php tools/sync-skeleton-requirements.php <source-composer.json> <target-composer.json> [framework-version]\n");
    exit(1);
}

[$_, $sourcePath, $targetPath] = $argv;
// Optional: pin waaseyaa/framework to the release being cut (bare or v-prefixed semver).
$frameworkVersion = $argv[3] ?? null;

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
function syncSkeletonRequirements(array $source, array $target, ?string $frameworkVersion = null): array
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

    // Pin waaseyaa/framework to the release being cut so the published skeleton
    // never drifts behind the framework it ships against (issue #1622). Only
    // overrides an already-declared pin; never injects the dependency.
    if (
        $frameworkVersion !== null
        && $frameworkVersion !== ''
        && isset($nextRequire['waaseyaa/framework'])
    ) {
        $nextRequire['waaseyaa/framework'] = '^' . ltrim($frameworkVersion, 'v');
    }

    ksort($nextRequire);

    $target['require'] = $nextRequire;

    return $target;
}

try {
    $source = loadComposerJson($sourcePath);
    $target = loadComposerJson($targetPath);
    $updated = syncSkeletonRequirements($source, $target, $frameworkVersion);

    file_put_contents(
        $targetPath,
        json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
