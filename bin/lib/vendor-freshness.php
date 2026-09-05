<?php

declare(strict_types=1);

/**
 * Shared vendor/ freshness precondition for repository gate scripts (#2926).
 *
 * A gate that runs against a STALE local vendor/ — vendor/composer/installed.json
 * older than composer.lock, a locked package missing, a root PSR-4 mapping never
 * re-dumped — does not observe a repository defect; it observes an environment
 * fault. Left unguarded, that fault surfaces as an uncaught PHP fatal (exit 255
 * plus a stack trace) or, worse, as a plausible-looking gate failure whose
 * repair text tells the contributor to change the repository.
 *
 * This file is the single precondition those gates share. It is intentionally
 * DEPENDENCY-FREE: it must run correctly precisely when the application
 * autoloader is broken, so it never requires vendor/autoload.php — only plain
 * file reads and a require of the generated autoload_psr4.php array map.
 *
 * Callers:
 *   bin/check-vendor-fresh              — the standalone local guard
 *   bin/check-pr-preflight              — runs it once before any gate
 *   bin/check-delivery-agent-events     — before dereferencing opis/json-schema
 *   tools/check-surface-parity.php      — before trusting class_exists()
 *   bin/generate-surface-map            — same declaration plane, same guard
 *
 * Contract:
 *   vendor_freshness_problem($root)  returns null when vendor/ is in sync with
 *                                    composer.lock and composer.json, otherwise
 *                                    ['what' => ..., 'detail' => ..., 'fix' => ...]
 *   vendor_freshness_message(...)    renders that problem as one actionable
 *                                    stderr block naming the calling tool
 *   VENDOR_FRESHNESS_EXIT_CODE       the exit code every caller uses for this
 *                                    outcome — distinct from 0 (pass), 1 (a real
 *                                    repository defect), 2 (the gates' own
 *                                    infrastructure failures) and 255 (PHP fatal),
 *                                    so a wrapper can tell "your checkout is
 *                                    stale" from "your change is wrong".
 *
 * Checks, in order, each mapped to its exact fix:
 *   0. vendor/autoload.php exists                                  → composer install
 *   1. composer.lock and vendor/composer/installed.json are readable JSON
 *   2. package SET: every package locked in composer.lock (packages AND
 *      packages-dev) is installed, and nothing is installed that the lock
 *      does not name                                               → composer install
 *   3. package IDENTITY: for every locked package, the installed version and
 *      source/dist reference equal the locked ones                → composer install
 *   4. every PSR-4 namespace the root composer.json declares (autoload +
 *      autoload-dev) is present in vendor/composer/autoload_psr4.php
 *                                                                  → composer dump-autoload
 */

const VENDOR_FRESHNESS_EXIT_CODE = 3;

/**
 * @return array{what: string, detail: string, fix: string}|null null when fresh
 */
function vendor_freshness_problem(string $root): ?array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');

    if (!is_file("{$root}/vendor/autoload.php")) {
        return vendor_freshness_stale('vendor/ is not installed', 'vendor/autoload.php does not exist.', 'composer install');
    }

    $lock = vendor_freshness_read_json("{$root}/composer.lock", 'composer.lock');
    if (isset($lock['__problem'])) {
        return $lock['__problem'];
    }
    $installed = vendor_freshness_read_json("{$root}/vendor/composer/installed.json", 'vendor/composer/installed.json');
    if (isset($installed['__problem'])) {
        return $installed['__problem'];
    }

    // composer 2 always writes installed.json as {"packages": [...]}.
    $installedPackages = is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
    $lockedPackages = array_merge(
        is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
        is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
    );

    /** @var array<string, array<string, mixed>> $lockedByName */
    $lockedByName = [];
    foreach ($lockedPackages as $package) {
        if (is_array($package) && is_string($package['name'] ?? null)) {
            $lockedByName[$package['name']] = $package;
        }
    }
    /** @var array<string, array<string, mixed>> $installedByName */
    $installedByName = [];
    foreach ($installedPackages as $package) {
        if (is_array($package) && is_string($package['name'] ?? null)) {
            $installedByName[$package['name']] = $package;
        }
    }

    $missing = array_values(array_diff(array_keys($lockedByName), array_keys($installedByName)));
    if ($missing !== []) {
        return vendor_freshness_stale(
            sprintf('%d locked package(s) not installed', count($missing)),
            sprintf(
                'e.g. %s — composer.lock has them but vendor/ does not (un-installed path-repo, new dependency, or a --no-dev install).',
                implode(', ', array_slice($missing, 0, 5)),
            ),
            'composer install',
        );
    }

    $extra = array_values(array_diff(array_keys($installedByName), array_keys($lockedByName)));
    if ($extra !== []) {
        return vendor_freshness_stale(
            sprintf('%d installed package(s) not in composer.lock', count($extra)),
            sprintf('e.g. %s — vendor/ has them but composer.lock does not.', implode(', ', array_slice($extra, 0, 5))),
            'composer install',
        );
    }

    foreach ($lockedByName as $name => $locked) {
        $mismatch = vendor_freshness_identity_mismatch($locked, $installedByName[$name]);
        if ($mismatch !== null) {
            return vendor_freshness_stale(
                sprintf('%s is installed at a different %s than composer.lock records', $name, $mismatch['field']),
                sprintf('%s: locked %s %s, installed %s.', $name, $mismatch['field'], $mismatch['locked'], $mismatch['installed']),
                'composer install',
            );
        }
    }

    $composer = vendor_freshness_read_json("{$root}/composer.json", 'composer.json');
    if (isset($composer['__problem'])) {
        return $composer['__problem'];
    }
    $declaredNamespaces = array_values(array_filter(array_merge(
        array_keys(is_array($composer['autoload']['psr-4'] ?? null) ? $composer['autoload']['psr-4'] : []),
        array_keys(is_array($composer['autoload-dev']['psr-4'] ?? null) ? $composer['autoload-dev']['psr-4'] : []),
    ), 'is_string'));

    $autoloadMapPath = "{$root}/vendor/composer/autoload_psr4.php";
    if (!is_file($autoloadMapPath)) {
        return vendor_freshness_stale(
            'the autoloader is not generated',
            'vendor/composer/autoload_psr4.php does not exist.',
            'composer dump-autoload',
        );
    }
    /** @var mixed $psr4 */
    $psr4 = require $autoloadMapPath;
    if (!is_array($psr4)) {
        return vendor_freshness_stale(
            'the generated autoloader is corrupt',
            'vendor/composer/autoload_psr4.php did not return an array.',
            'composer dump-autoload',
        );
    }
    $missingNamespaces = array_values(array_filter(
        $declaredNamespaces,
        static fn(string $namespace): bool => !isset($psr4[$namespace]),
    ));
    if ($missingNamespaces !== []) {
        return vendor_freshness_stale(
            sprintf('%d declared PSR-4 namespace(s) missing from the autoloader', count($missingNamespaces)),
            sprintf(
                'e.g. %s — composer.json declares them but the dumped autoloader does not.',
                implode(', ', array_slice($missingNamespaces, 0, 5)),
            ),
            'composer dump-autoload',
        );
    }

    return null;
}

/**
 * One actionable stderr block for a stale vendor/, prefixed with the calling
 * tool's name so it reads like every other line that tool prints.
 *
 * @param array{what: string, detail: string, fix: string} $problem
 */
function vendor_freshness_message(array $problem, string $tool): string
{
    return sprintf(
        "%s: vendor/ is stale relative to composer.lock — %s.\n%s:   %s\n%s:   This is an environment fault, not a repository defect: run `%s` and re-run the gate.\n",
        $tool,
        $problem['what'],
        $tool,
        $problem['detail'],
        $tool,
        $problem['fix'],
    );
}

/**
 * @return array{what: string, detail: string, fix: string}
 */
function vendor_freshness_stale(string $what, string $detail, string $fix): array
{
    return ['what' => $what, 'detail' => $detail, 'fix' => $fix];
}

/**
 * @return array<string, mixed> the decoded document, or ['__problem' => problem] when it cannot be read
 */
function vendor_freshness_read_json(string $path, string $label): array
{
    if (!is_file($path)) {
        return ['__problem' => vendor_freshness_stale(
            "{$label} is missing",
            sprintf('%s does not exist at %s.', $label, $path),
            'composer install',
        )];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['__problem' => vendor_freshness_stale("{$label} is unreadable", sprintf('Could not read %s.', $path), 'composer install')];
    }
    try {
        /** @var mixed $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
        return ['__problem' => vendor_freshness_stale("{$label} is corrupt", sprintf('%s: %s', $path, $exception->getMessage()), 'composer install')];
    }
    if (!is_array($data)) {
        return ['__problem' => vendor_freshness_stale("{$label} is corrupt", sprintf('%s is not a JSON object.', $path), 'composer install')];
    }

    return $data;
}

/**
 * Compare the identity fields composer.lock records for a package against the
 * installed copy. Only fields the lock actually carries are compared, so a
 * lock entry without a reference (or a fixture without a version) is not a
 * mismatch by omission.
 *
 * @param array<string, mixed> $locked
 * @param array<string, mixed> $installed
 * @return array{field: string, locked: string, installed: string}|null
 */
function vendor_freshness_identity_mismatch(array $locked, array $installed): ?array
{
    $fields = [
        'version' => static fn(array $package): ?string => is_string($package['version'] ?? null) ? $package['version'] : null,
        'source reference' => static fn(array $package): ?string => is_string($package['source']['reference'] ?? null) ? $package['source']['reference'] : null,
        'dist reference' => static fn(array $package): ?string => is_string($package['dist']['reference'] ?? null) ? $package['dist']['reference'] : null,
    ];
    foreach ($fields as $field => $read) {
        $lockedValue = $read($locked);
        if ($lockedValue === null) {
            continue;
        }
        $installedValue = $read($installed);
        if ($installedValue !== $lockedValue) {
            return ['field' => $field, 'locked' => $lockedValue, 'installed' => $installedValue ?? '(none)'];
        }
    }

    return null;
}
