<?php

declare(strict_types=1);

/**
 * Shared library for internal waaseyaa/* version synchronisation.
 *
 * Used by bin/sync-internal-versions (WP01) and bin/check-composer-policy
 * CP-NEW gate (WP03). All public functions are @api — they are intentional
 * extension points shared across multiple bin scripts.
 *
 * @api
 */

/**
 * Resolve the version represented by the checked-out repository tree.
 *
 * VERSION is tracked and advances in the same release commit as package
 * constraints, so it remains deterministic when a client has fetched main but
 * not the corresponding tag ref. Repositories without VERSION retain the
 * historical tag-based behaviour used by older consumers and test fixtures.
 *
 * @throws \RuntimeException When VERSION is unreadable/invalid and when the
 *                           tag fallback cannot resolve a release.
 */
function resolveRepositoryVersion(?string $repoRoot = null): string
{
    if ($repoRoot === null) {
        $repoRoot = dirname(__DIR__, 2);
    }

    $versionPath = rtrim($repoRoot, '/') . '/VERSION';
    if (!is_file($versionPath)) {
        return resolveCurrentVersion($repoRoot);
    }

    $contents = file_get_contents($versionPath);
    if ($contents === false) {
        throw new \RuntimeException(sprintf('Could not read tracked version from %s.', $versionPath));
    }

    try {
        return validateVersionInput(trim($contents));
    } catch (\InvalidArgumentException $e) {
        throw new \RuntimeException(sprintf('Invalid tracked VERSION file %s: %s', $versionPath, $e->getMessage()), 0, $e);
    }
}

/**
 * Resolve the most recent semver git tag and return it without the leading 'v'.
 *
 * @param string|null $repoRoot Absolute path to the repository root.
 *                              Defaults to two directories above this file
 *                              (i.e. the monorepo root when the file lives at
 *                              bin/lib/internal-version-sync.php).
 *
 * @throws \RuntimeException When no matching tag is found or git fails.
 */
function resolveCurrentVersion(?string $repoRoot = null): string
{
    if ($repoRoot === null) {
        $repoRoot = dirname(__DIR__, 2);
    }

    $repoRoot = rtrim($repoRoot, '/');

    $cmd = sprintf(
        'git -C %s describe --tags --abbrev=0 --match=%s 2>&1',
        escapeshellarg($repoRoot),
        escapeshellarg('v*.*.*'),
    );

    $output = shell_exec($cmd);

    if ($output === null || $output === false || trim($output) === '') {
        throw new \RuntimeException(
            sprintf('Could not resolve current version from git tags in %s.', $repoRoot),
        );
    }

    $tag = trim($output);

    if (!str_starts_with($tag, 'v')) {
        throw new \RuntimeException(
            sprintf('Unexpected git tag format (expected v-prefix): %s', $tag),
        );
    }

    return ltrim($tag, 'v');
}

/**
 * Return the caret constraint string for a given version.
 *
 * @param string $version Version without leading 'v' (e.g. "0.1.0-alpha.176").
 */
function expectedConstraint(string $version): string
{
    return '^' . $version;
}

/**
 * Extract all waaseyaa/* package keys present in require and/or require-dev.
 *
 * @param array<string, mixed> $manifest Parsed composer.json array.
 *
 * @return list<string> Deduplicated, sorted list of waaseyaa/* package names.
 */
function findInternalDeps(array $manifest): array
{
    /** @var array<string, string> $require */
    $require = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
    /** @var array<string, string> $requireDev */
    $requireDev = is_array($manifest['require-dev'] ?? null) ? $manifest['require-dev'] : [];

    $all = array_merge(array_keys($require), array_keys($requireDev));

    $internal = array_values(array_unique(array_filter(
        $all,
        static fn (string $pkg): bool => str_starts_with($pkg, 'waaseyaa/'),
    )));

    sort($internal);

    return $internal;
}

/**
 * Validate and normalise a version string.
 *
 * Accepts versions with or without a leading 'v' prefix and returns the
 * normalised form without the prefix.
 *
 * Rejects:
 *  - empty / whitespace-only strings
 *  - dev-* aliases
 *  - the literal "self.version"
 *  - strings containing wildcard characters (* ? x X)
 *  - strings not matching the semver prerelease shape
 *    ^v?[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.-]+)?$
 *
 * @throws \InvalidArgumentException On any invalid input.
 */
function validateVersionInput(string $input): string
{
    if (trim($input) === '') {
        throw new \InvalidArgumentException(
            'Version must not be empty or whitespace.',
        );
    }

    if (str_contains($input, ' ') || str_contains($input, "\t")) {
        throw new \InvalidArgumentException(
            sprintf('Version must not contain whitespace: "%s".', $input),
        );
    }

    if (str_starts_with(strtolower($input), 'dev-')) {
        throw new \InvalidArgumentException(
            sprintf('dev-* aliases are not valid release versions: "%s".', $input),
        );
    }

    if ($input === 'self.version') {
        throw new \InvalidArgumentException(
            '"self.version" is a Composer meta-token, not a release version.',
        );
    }

    foreach (['*', '?', 'x', 'X'] as $wildcard) {
        if (str_contains($input, $wildcard)) {
            throw new \InvalidArgumentException(
                sprintf('Version must not contain wildcard or placeholder characters: "%s".', $input),
            );
        }
    }

    $pattern = '/^v?[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.-]+)?$/';
    if (preg_match($pattern, $input) !== 1) {
        throw new \InvalidArgumentException(
            sprintf(
                'Version "%s" does not match the expected shape '
                . '(e.g. 0.1.0-alpha.176 or v0.1.0-alpha.176).',
                $input,
            ),
        );
    }

    return ltrim($input, 'v');
}

/**
 * Rewrite waaseyaa/* constraints in the require and require-dev sections of a
 * single composer.json file, preserving JSON formatting. Composer's suggest
 * values are descriptions, not constraints, and are deliberately excluded.
 *
 * Uses a regex-based in-place substitution so that indentation, trailing
 * commas (where the JSON was originally hand-authored with them), and key
 * ordering are all preserved byte-for-byte for every line that does NOT
 * contain a waaseyaa/* constraint.
 *
 * @throws \RuntimeException On read/write failure.
 */
/**
 * Discover every composer.json whose waaseyaa/* constraints the release process
 * keeps on the released line: all split packages (packages/(*)/composer.json)
 * AND the create-project skeleton (skeleton/composer.json) when present.
 *
 * The skeleton is included so `composer create-project waaseyaa/waaseyaa` always
 * installs the CURRENT framework line rather than drifting behind it (#1622):
 * before this, only packages/* were synced, so the skeleton's
 * `waaseyaa/framework` constraint lagged every release (it sat at alpha.199 while
 * the line was alpha.242), and a `create-project` could resolve a months-old set.
 *
 * @return list<string> Absolute manifest paths (the skeleton last, when present).
 */
function discoverSyncManifests(string $repoRoot): array
{
    $manifests = glob($repoRoot . '/packages/*/composer.json');
    if ($manifests === false) {
        $manifests = [];
    }

    $skeleton = $repoRoot . '/skeleton/composer.json';
    if (is_file($skeleton)) {
        $manifests[] = $skeleton;
    }

    return $manifests;
}

function syncManifestFile(string $manifestPath, string $constraint): bool
{
    $original = file_get_contents($manifestPath);
    if ($original === false) {
        throw new \RuntimeException(sprintf('Cannot read %s', $manifestPath));
    }

    // Restrict replacement to dependency constraint objects. A waaseyaa/* key
    // may also appear under suggest, where its value is human-readable prose.
    $updated = preg_replace_callback(
        '/"(?:require|require-dev)"\s*:\s*\{.*?\}/s',
        static function (array $sectionMatches) use ($constraint): string {
            $section = preg_replace_callback(
                '/"(waaseyaa\/[^"]+)"\s*:\s*"([^"]*)"/',
                static function (array $dependencyMatches) use ($constraint): string {
                    return '"' . $dependencyMatches[1] . '": "' . $constraint . '"';
                },
                $sectionMatches[0],
            );

            if ($section === null) {
                throw new \RuntimeException('Constraint substitution failed inside dependency section.');
            }

            return $section;
        },
        $original,
    );

    if ($updated === null) {
        throw new \RuntimeException(sprintf('Regex substitution failed for %s', $manifestPath));
    }

    if ($updated === $original) {
        return false;
    }

    $written = file_put_contents($manifestPath, $updated);
    if ($written === false) {
        throw new \RuntimeException(sprintf('Cannot write %s', $manifestPath));
    }

    return true;
}

/**
 * Find structural drift between path-package manifests and root-lock metadata.
 *
 * Composer copies each local package's production `require` object into the
 * root lock. Internal constraint values intentionally move during a release
 * sweep, but adding or removing a dependency key requires Composer to
 * regenerate the lock entry first.
 *
 * @param list<string> $manifestPaths Absolute package manifest paths.
 *
 * @return list<array{package: string, missing: list<string>, extra: list<string>}>
 *
 * @throws \RuntimeException On malformed JSON or read failure.
 */
function findRootLockDependencyKeyDrift(string $lockPath, array $manifestPaths): array
{
    [, $lock, $requiresByPackage] = readRootLockDependencyMetadata($lockPath, $manifestPaths);
    $drift = [];

    foreach (['packages', 'packages-dev'] as $section) {
        $packages = $lock->{$section} ?? null;
        if (!is_array($packages)) {
            continue;
        }

        foreach ($packages as $package) {
            if (!$package instanceof \stdClass) {
                continue;
            }
            $name = $package->name ?? null;
            if (!is_string($name) || !isset($requiresByPackage[$name])) {
                continue;
            }

            $lockedRequireObject = $package->require ?? null;
            $lockedRequire = $lockedRequireObject instanceof \stdClass
                ? get_object_vars($lockedRequireObject)
                : [];
            $lockedKeys = array_keys($lockedRequire);
            $expectedKeys = array_keys($requiresByPackage[$name]);
            $missing = array_values(array_diff($expectedKeys, $lockedKeys));
            $extra = array_values(array_diff($lockedKeys, $expectedKeys));
            sort($missing);
            sort($extra);

            if ($missing !== [] || $extra !== []) {
                $drift[] = [
                    'package' => $name,
                    'missing' => $missing,
                    'extra' => $extra,
                ];
            }
        }
    }

    return $drift;
}

/**
 * Read the root lock and the production requirements of local package manifests.
 *
 * @param list<string> $manifestPaths Absolute package manifest paths.
 *
 * @return array{string, \stdClass, array<string, array<string, string>>}
 *
 * @throws \RuntimeException On malformed JSON or read failure.
 */
function readRootLockDependencyMetadata(string $lockPath, array $manifestPaths): array
{
    $original = file_get_contents($lockPath);
    if ($original === false) {
        throw new \RuntimeException(sprintf('Cannot read %s', $lockPath));
    }

    try {
        $lock = json_decode($original, false, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new \RuntimeException(sprintf('Cannot parse %s: %s', $lockPath, $e->getMessage()), 0, $e);
    }
    if (!$lock instanceof \stdClass) {
        throw new \RuntimeException(sprintf('%s must contain a JSON object.', $lockPath));
    }

    /** @var array<string, array<string, string>> $requiresByPackage */
    $requiresByPackage = [];
    foreach ($manifestPaths as $manifestPath) {
        $contents = file_get_contents($manifestPath);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Cannot read %s', $manifestPath));
        }

        try {
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Cannot parse %s: %s', $manifestPath, $e->getMessage()), 0, $e);
        }

        $name = is_array($manifest) ? ($manifest['name'] ?? null) : null;
        $require = is_array($manifest) ? ($manifest['require'] ?? null) : null;
        if (is_string($name) && is_array($require)) {
            /** @var array<string, string> $require */
            $requiresByPackage[$name] = $require;
        }
    }

    return [$original, $lock, $requiresByPackage];
}

/**
 * Synchronize path-package dependency metadata embedded in the root lock.
 *
 * Composer copies each path package's production `require` object into
 * composer.lock. The release cut mutates package manifests without running a
 * network-dependent Composer update, so that copied metadata must move in the
 * same deterministic operation. Third-party packages, content-hash, versions,
 * references, and every other lock field remain untouched.
 *
 * @param list<string> $manifestPaths Absolute package manifest paths.
 *
 * @throws \RuntimeException On malformed JSON or read/write failure.
 */
function syncRootLockFile(string $lockPath, array $manifestPaths): bool
{
    $dependencyKeyDrift = findRootLockDependencyKeyDrift($lockPath, $manifestPaths);
    if ($dependencyKeyDrift !== []) {
        throw new \RuntimeException(sprintf(
            '%s package %s has dependency-key drift; regenerate the root lock before release.',
            $lockPath,
            $dependencyKeyDrift[0]['package'],
        ));
    }
    [$original, $lock, $requiresByPackage] = readRootLockDependencyMetadata($lockPath, $manifestPaths);

    $changed = false;
    foreach (['packages', 'packages-dev'] as $section) {
        $packages = $lock->{$section} ?? null;
        if (!is_array($packages)) {
            continue;
        }

        foreach ($packages as $package) {
            if (!$package instanceof \stdClass) {
                continue;
            }
            $name = $package->name ?? null;
            if (!is_string($name) || !isset($requiresByPackage[$name])) {
                continue;
            }
            $lockedRequireObject = $package->require ?? null;
            $lockedRequire = $lockedRequireObject instanceof \stdClass
                ? get_object_vars($lockedRequireObject)
                : [];
            $expectedRequire = $requiresByPackage[$name];
            foreach ($expectedRequire as $dependency => $constraint) {
                if (!str_starts_with($dependency, 'waaseyaa/')) {
                    if (($lockedRequire[$dependency] ?? null) !== $constraint) {
                        throw new \RuntimeException(sprintf(
                            '%s package %s has non-internal dependency drift for %s; regenerate the root lock before release.',
                            $lockPath,
                            $name,
                            $dependency,
                        ));
                    }
                    continue;
                }

                if (($lockedRequire[$dependency] ?? null) !== $constraint) {
                    if (!$lockedRequireObject instanceof \stdClass) {
                        throw new \RuntimeException(sprintf(
                            '%s package %s has invalid require metadata.',
                            $lockPath,
                            $name,
                        ));
                    }
                    $lockedRequireObject->{$dependency} = $constraint;
                    $changed = true;
                }
            }
        }
    }

    if (!$changed) {
        return false;
    }

    try {
        $updated = json_encode(
            $lock,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    } catch (\JsonException $e) {
        throw new \RuntimeException(sprintf('Cannot encode %s: %s', $lockPath, $e->getMessage()), 0, $e);
    }

    if (file_put_contents($lockPath, $updated) === false) {
        throw new \RuntimeException(sprintf('Cannot write %s', $lockPath));
    }

    return true;
}
