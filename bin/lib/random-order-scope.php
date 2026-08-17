<?php

declare(strict_types=1);

/**
 * Fail-closed manifest loader for the random-order CI test selector
 * (docs/specs/ci-test-selection.md §3.3). Plain functions, no autoloader:
 * this must run before `vendor/` exists in some CI jobs.
 */

final class RosScopeFailure extends RuntimeException {}

/**
 * @return array{protected: list<string>, prefixes: array<string, list<string>>}
 */
function ros_load_manifest(string $root): array
{
    $path = $root . '/tools/random-order-scope-manifest.json';
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RosScopeFailure('scope manifest is unreadable: tools/random-order-scope-manifest.json');
    }

    try {
        $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RosScopeFailure('scope manifest is unparsable: ' . $exception->getMessage());
    }

    if (!is_array($document) || ($document['schema_version'] ?? null) !== 1) {
        throw new RosScopeFailure('scope manifest must declare schema_version 1');
    }

    $packages = [];
    foreach (glob($root . '/packages/*/composer.json') ?: [] as $manifestPath) {
        $packages[basename(dirname($manifestPath))] = true;
    }

    $prefixes = [];
    foreach ($document['prefixes'] ?? [] as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null) || $entry['path'] === '') {
            throw new RosScopeFailure('scope manifest entry is missing a path');
        }
        if (!is_string($entry['rationale'] ?? null) || trim($entry['rationale']) === '') {
            throw new RosScopeFailure("scope manifest entry {$entry['path']} is missing a rationale");
        }
        $seeds = $entry['seeds'] ?? [];
        if (!is_array($seeds)) {
            throw new RosScopeFailure("scope manifest entry {$entry['path']} has a malformed seeds list");
        }
        foreach ($seeds as $seed) {
            if (!is_string($seed) || !isset($packages[$seed])) {
                throw new RosScopeFailure("scope manifest entry {$entry['path']} seeds an absent package");
            }
        }
        $prefixes[$entry['path']] = array_values($seeds);
    }

    // A directory prefix may host a longer exact-file entry, but two directory
    // prefixes where one contains the other make classification ambiguous.
    foreach (array_keys($prefixes) as $outer) {
        if (!str_ends_with($outer, '/')) {
            continue;
        }
        foreach (array_keys($prefixes) as $inner) {
            if ($inner !== $outer && str_ends_with($inner, '/') && str_starts_with($inner, $outer)) {
                throw new RosScopeFailure("ambiguous manifest prefix: {$inner} is shadowed by {$outer}");
            }
        }
    }

    $protected = $document['protected'] ?? [];
    if (!is_array($protected)) {
        throw new RosScopeFailure('scope manifest protected list is malformed');
    }
    foreach ($protected as $entry) {
        if (!is_string($entry) || $entry === '') {
            throw new RosScopeFailure('scope manifest protected list contains a malformed entry');
        }
    }

    ksort($prefixes);
    sort($protected);

    return ['protected' => array_values($protected), 'prefixes' => $prefixes];
}

/**
 * Parses `git diff --name-status -z` output (docs/specs/ci-test-selection.md
 * §3.2) into a sorted, de-duplicated path list. Rename (`R<score>`) and copy
 * (`C<score>`) records carry two operand paths; both are classified so a
 * rename across a group boundary seeds both groups.
 *
 * @return list<string>
 */
function ros_parse_name_status(string $raw): array
{
    $fields = explode("\0", $raw);
    $paths = [];
    $index = 0;
    $count = count($fields);

    while ($index < $count) {
        $status = $fields[$index];
        if ($status === '') {
            ++$index;
            continue;
        }
        $letter = $status[0];
        $operands = ($letter === 'R' || $letter === 'C') ? 2 : 1;
        if ($index + $operands >= $count) {
            throw new RosScopeFailure('git name-status output is truncated');
        }
        for ($offset = 1; $offset <= $operands; ++$offset) {
            $path = $fields[$index + $offset];
            if ($path === '') {
                throw new RosScopeFailure('git name-status output contains an empty path');
            }
            $paths[$path] = true;
        }
        $index += $operands + 1;
    }

    $paths = array_keys($paths);
    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * Classifies changed paths per docs/specs/ci-test-selection.md §3.3: first
 * match wins, and anything the selector cannot prove bounded forces the
 * complete inventory (`full_reason` non-null).
 *
 * @param list<string> $paths
 * @param array{protected: list<string>, prefixes: array<string, list<string>>} $manifest
 * @return array{seeds: list<string>, full_reason: ?string}
 */
function ros_classify(array $paths, array $manifest, string $root): array
{
    $protected = array_flip($manifest['protected']);
    $seeds = [];

    foreach ($paths as $path) {
        if (isset($protected[$path])) {
            return ['seeds' => [], 'full_reason' => "selection input changed: {$path}"];
        }
        if (preg_match('#^packages/[^/]+/composer\.json$#', $path) === 1) {
            return ['seeds' => [], 'full_reason' => "selection input changed: {$path}"];
        }

        if (preg_match('#^packages/([^/]+)/#', $path, $matches) === 1) {
            $package = $matches[1];
            $manifestPath = $root . '/packages/' . $package . '/composer.json';
            $declared = is_file($manifestPath)
                ? json_decode((string) @file_get_contents($manifestPath), true)
                : null;
            if (!is_array($declared) || !is_string($declared['name'] ?? null)) {
                return [
                    'seeds' => [],
                    'full_reason' => "package is absent from the dependency graph: {$package}",
                ];
            }
            $seeds[$package] = true;
            continue;
        }

        if (str_starts_with($path, 'tests/')) {
            // Attribution happens in ros_select(); an unattributable tests/ file
            // pins its group through the always-run set instead of seeding.
            continue;
        }

        $matched = null;
        foreach ($manifest['prefixes'] as $prefix => $prefixSeeds) {
            $hit = str_ends_with($prefix, '/') ? str_starts_with($path, $prefix) : $path === $prefix;
            if ($hit && ($matched === null || strlen($prefix) > strlen($matched))) {
                $matched = $prefix;
            }
        }
        if ($matched === null) {
            return ['seeds' => [], 'full_reason' => "unclassified path: {$path}"];
        }
        foreach ($manifest['prefixes'][$matched] as $seed) {
            $seeds[$seed] = true;
        }
    }

    $seeds = array_keys($seeds);
    sort($seeds, SORT_STRING);

    return ['seeds' => $seeds, 'full_reason' => null];
}

/**
 * Builds the internal dependency graph (docs/specs/ci-test-selection.md
 * §3.4.1-2): `reverse` maps a package to its direct consumers over `require`
 * **and** `require-dev` (this repository permits upward dev edges), and
 * `psr4` maps a namespace prefix to the package directory that declares it.
 * Graph integrity problems (unparsable/unreadable manifest, no declared
 * name, duplicate name, or an internal requirement naming an absent
 * package) are fail-closed `RosScopeFailure`s.
 *
 * @return array{reverse: array<string, list<string>>, psr4: array<string, string>}
 */
function ros_package_graph(string $root): array
{
    $byName = [];
    $manifests = [];
    foreach (glob($root . '/packages/*/composer.json') ?: [] as $path) {
        $directory = basename(dirname($path));
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RosScopeFailure("package manifest is unreadable: packages/{$directory}/composer.json");
        }
        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RosScopeFailure(
                "package manifest is unparsable: packages/{$directory}/composer.json — {$exception->getMessage()}",
            );
        }
        if (!is_array($document) || !is_string($document['name'] ?? null)) {
            throw new RosScopeFailure("package manifest declares no name: packages/{$directory}/composer.json");
        }
        if (isset($byName[$document['name']])) {
            throw new RosScopeFailure("duplicate package name: {$document['name']}");
        }
        $byName[$document['name']] = $directory;
        $manifests[$directory] = $document;
    }

    $reverse = [];
    $psr4 = [];
    foreach ($manifests as $directory => $document) {
        $reverse[$directory] ??= [];
        foreach (array_merge($document['require'] ?? [], $document['require-dev'] ?? []) as $name => $_constraint) {
            if (!str_starts_with((string) $name, 'waaseyaa/')) {
                continue;
            }
            if (!isset($byName[$name])) {
                throw new RosScopeFailure(
                    "packages/{$directory}/composer.json requires an absent internal package: {$name}",
                );
            }
            $reverse[$byName[$name]][] = $directory;
        }
        foreach (array_merge(
            $document['autoload']['psr-4'] ?? [],
            $document['autoload-dev']['psr-4'] ?? [],
        ) as $namespace => $_target) {
            $psr4[rtrim((string) $namespace, '\\')] = $directory;
        }
    }

    foreach ($reverse as $package => $consumers) {
        $consumers = array_values(array_unique($consumers));
        sort($consumers, SORT_STRING);
        $reverse[$package] = $consumers;
    }
    ksort($reverse);
    ksort($psr4);

    return ['reverse' => $reverse, 'psr4' => $psr4];
}

/**
 * Transitively closes the seed set over reversed internal dependency edges
 * (docs/specs/ci-test-selection.md §3.4.1). The visited set bounds the walk,
 * so the five accepted same-layer 2-cycles (tools/package-layers-cycle-baseline.txt)
 * terminate instead of erroring (§3.4.2).
 *
 * @param array<string, list<string>> $reverse
 * @param list<string> $seeds
 * @return list<string>
 */
function ros_closure(array $reverse, array $seeds): array
{
    $seen = [];
    $queue = $seeds;
    while ($queue !== []) {
        $package = array_pop($queue);
        if (isset($seen[$package])) {
            continue; // visited-set bound: accepted 2-cycles terminate here
        }
        $seen[$package] = true;
        foreach ($reverse[$package] ?? [] as $consumer) {
            $queue[] = $consumer;
        }
    }

    $closure = array_keys($seen);
    sort($closure, SORT_STRING);

    return $closure;
}
