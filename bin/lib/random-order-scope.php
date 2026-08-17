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
 * Finds the longest manifest prefix matching a changed path (directory
 * prefixes ending `/` match by `str_starts_with`; exact-file entries match
 * literally). Shared by `ros_classify()`'s package-manifest fallback (R9)
 * and its catch-all prefix lookup, so both use one matching rule.
 *
 * @param array<string, list<string>> $prefixes
 */
function ros_match_prefix(array $prefixes, string $path): ?string
{
    $matched = null;
    foreach ($prefixes as $prefix => $prefixSeeds) {
        $hit = str_ends_with($prefix, '/') ? str_starts_with($path, $prefix) : $path === $prefix;
        if ($hit && ($matched === null || strlen($prefix) > strlen($matched))) {
            $matched = $prefix;
        }
    }

    return $matched;
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
            if (is_array($declared) && is_string($declared['name'] ?? null)) {
                $seeds[$package] = true;
                continue;
            }

            // No parsable package manifest (e.g. packages/admin/, a Nuxt SPA
            // with no composer.json by design): fall back to a manifest
            // prefix match instead of forcing full outright. A package with
            // no manifest AND no manifest-declared prefix still forces full.
            $matchedPrefix = ros_match_prefix($manifest['prefixes'], $path);
            if ($matchedPrefix === null) {
                return [
                    'seeds' => [],
                    'full_reason' => "package is absent from the dependency graph: {$package}",
                ];
            }
            foreach ($manifest['prefixes'][$matchedPrefix] as $seed) {
                $seeds[$seed] = true;
            }
            continue;
        }

        if (str_starts_with($path, 'tests/')) {
            // Attribution happens in ros_select(); an unattributable tests/ file
            // pins its group through the always-run set instead of seeding.
            continue;
        }

        $matched = ros_match_prefix($manifest['prefixes'], $path);
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

/**
 * The atomic group a path belongs to (docs/specs/ci-test-selection.md §1,
 * "the atomic group is the group `bin/build-phpunit-shards` already uses"):
 * `packages/<name>`, `tests/<TopDir>`, or `tests`.
 */
function ros_group_of(string $path): string
{
    if (preg_match('#^packages/([^/]+)/#', $path, $matches) === 1) {
        return 'packages/' . $matches[1];
    }
    if (preg_match('#^tests/([^/]+)/#', $path, $matches) === 1) {
        return 'tests/' . $matches[1];
    }

    return 'tests';
}

/**
 * Discovers the configured test inventory from phpunit.xml.dist. Fail-closed:
 * an unparsable config or an empty discovered inventory is a RosScopeFailure.
 *
 * @return array<string, string> inventory path => suite name
 */
function ros_inventory(string $root): array
{
    $previous = libxml_use_internal_errors(true);
    $config = simplexml_load_file($root . '/phpunit.xml.dist', options: LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_use_internal_errors($previous);
    if (!$config instanceof SimpleXMLElement) {
        throw new RosScopeFailure('phpunit.xml.dist is unparsable');
    }

    $inventory = [];
    foreach ($config->testsuites->testsuite ?? [] as $suite) {
        $name = (string) $suite['name'];
        foreach ($suite->directory as $directory) {
            foreach (glob($root . '/' . trim((string) $directory), GLOB_ONLYDIR) ?: [] as $matched) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($matched, FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $candidate) {
                    if (!$candidate instanceof SplFileInfo
                        || !$candidate->isFile()
                        || $candidate->isLink()
                        || !str_ends_with($candidate->getPathname(), 'Test.php')
                    ) {
                        continue;
                    }
                    $relative = substr(str_replace('\\', '/', $candidate->getPathname()), strlen($root) + 1);
                    if (isset($inventory[$relative]) && $inventory[$relative] !== $name) {
                        throw new RosScopeFailure(
                            "test file is assigned to more than one suite: {$relative}",
                        );
                    }
                    $inventory[$relative] = $name;
                }
            }
        }
        foreach ($suite->file as $file) {
            $relative = trim((string) $file);
            if (isset($inventory[$relative]) && $inventory[$relative] !== $name) {
                throw new RosScopeFailure("test file is assigned to more than one suite: {$relative}");
            }
            $inventory[$relative] = $name;
        }
    }

    if ($inventory === []) {
        throw new RosScopeFailure('phpunit.xml.dist discovered no test files');
    }
    ksort($inventory);

    return $inventory;
}

/**
 * Attributes a `tests/**` file to the packages it imports (docs/specs/ci-test-selection.md
 * §3.5): each `use Waaseyaa\…` import is mapped to the longest matching PSR-4
 * prefix. Fails closed to `null` — pinning the file's group into the
 * always-run set — when the file is unreadable, declares no `Waaseyaa\…`
 * import, an import matches no declared prefix, or two prefixes of equal
 * length match.
 *
 * @param array<string, string> $psr4
 * @return list<string>|null null when the file cannot be attributed
 */
function ros_attribute(string $path, array $psr4, string $root): ?array
{
    $source = @file_get_contents($root . '/' . $path);
    if ($source === false) {
        return null;
    }

    preg_match_all('/^use\s+(Waaseyaa\\\\[A-Za-z0-9_\\\\]+)/m', $source, $matches);
    if ($matches[1] === []) {
        return null;
    }

    $packages = [];
    foreach ($matches[1] as $import) {
        $best = null;
        $bestLength = -1;
        $tied = false;
        foreach ($psr4 as $namespace => $package) {
            if ($import !== $namespace && !str_starts_with($import, $namespace . '\\')) {
                continue;
            }
            $length = strlen($namespace);
            if ($length > $bestLength) {
                $best = $package;
                $bestLength = $length;
                $tied = false;
            } elseif ($length === $bestLength && $package !== $best) {
                $tied = true;
            }
        }
        if ($best === null || $tied) {
            return null; // unmatched or ambiguous import: fail closed
        }
        $packages[$best] = true;
    }

    $packages = array_keys($packages);
    sort($packages, SORT_STRING);

    return $packages;
}

function ros_digest(string ...$paths): string
{
    $context = hash_init('sha256');
    sort($paths, SORT_STRING);
    foreach ($paths as $path) {
        hash_update($context, $path);
        hash_update($context, (string) @file_get_contents($path));
    }

    return 'sha256:' . hash_final($context);
}

/**
 * The canonical selection-document shape (docs/specs/ci-test-selection.md
 * §4). `ros_select()`'s success document and the CLI's `catch (Throwable)`
 * failure document both build from this, so the two are structurally
 * identical — same keys, same order — regardless of which path produced
 * them.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function ros_document(string $mode, ?string $reason, string $base, string $head, array $overrides = []): array
{
    $document = [
        'schema_version' => 1,
        'mode' => $mode,
        'fallback_reason' => $reason,
        'base_sha' => $base,
        'head_sha' => $head,
        'digests' => [],
        'seed_packages' => [],
        'closure_packages' => [],
        'always_run_groups' => [],
        'selected_groups' => [],
        'selected_paths' => [],
        'selected_files' => 0,
        'inventory_files' => 0,
    ];

    foreach ($overrides as $key => $value) {
        $document[$key] = $value;
    }

    return $document;
}

/** @return array<string, mixed> the selection document of docs/specs/ci-test-selection.md §4 */
function ros_select(string $root, string $base, string $head, ?string $forcedReason): array
{
    $inventory = ros_inventory($root);
    $graph = ros_package_graph($root);

    $groupPaths = [];
    $groupPackages = [];
    $alwaysRun = [];
    foreach (array_keys($inventory) as $path) {
        $group = ros_group_of($path);
        $groupPaths[$group][] = $path;
        if (preg_match('#^packages/([^/]+)/#', $path, $matches) === 1) {
            $groupPackages[$group][$matches[1]] = true;
            continue;
        }
        $attributed = ros_attribute($path, $graph['psr4'], $root);
        if ($attributed === null) {
            $alwaysRun[$group] = true;
            continue;
        }
        foreach ($attributed as $package) {
            $groupPackages[$group][$package] = true;
        }
    }

    $reason = $forcedReason;
    $seeds = [];
    $closure = [];
    if ($reason === null) {
        try {
            $manifest = ros_load_manifest($root);
            $changed = ros_parse_name_status(ros_diff($root, $base, $head));
            if ($changed === []) {
                $reason = 'the diff is empty';
            } else {
                $classified = ros_classify($changed, $manifest, $root);
                $reason = $classified['full_reason'];
                $seeds = $classified['seeds'];
                foreach ($changed as $path) {
                    if (str_starts_with($path, 'tests/')) {
                        $attributed = ros_attribute($path, $graph['psr4'], $root);
                        foreach ($attributed ?? [] as $package) {
                            $seeds[] = $package;
                        }
                    }
                }
                $seeds = array_values(array_unique($seeds));
                sort($seeds, SORT_STRING);
            }
        } catch (RosScopeFailure $failure) {
            $reason = $failure->getMessage();
        }
    }

    if ($reason === null) {
        $closure = ros_closure($graph['reverse'], $seeds);
        $selectedGroups = array_keys($alwaysRun);
        foreach ($groupPackages as $group => $packages) {
            if (array_intersect(array_keys($packages), $closure) !== []) {
                $selectedGroups[] = $group;
            }
        }
    } else {
        $selectedGroups = array_keys($groupPaths);
    }

    $selectedGroups = array_values(array_unique($selectedGroups));
    sort($selectedGroups, SORT_STRING);

    $selectedPaths = [];
    foreach ($selectedGroups as $group) {
        foreach ($groupPaths[$group] as $path) {
            $selectedPaths[] = $path; // atomic expansion: the whole group travels together
        }
    }
    sort($selectedPaths, SORT_STRING);

    $alwaysRunGroups = array_keys($alwaysRun);
    sort($alwaysRunGroups, SORT_STRING);

    $packageManifests = glob($root . '/packages/*/composer.json') ?: [];

    return ros_document($reason === null ? 'targeted' : 'full', $reason, $base, $head, [
        'digests' => [
            'manifest' => ros_digest($root . '/tools/random-order-scope-manifest.json'),
            'composer_graph' => ros_digest($root . '/composer.json', ...$packageManifests),
            'phpunit_config' => ros_digest($root . '/phpunit.xml.dist'),
            'selector' => ros_digest(
                $root . '/bin/select-random-order-scope',
                $root . '/bin/lib/random-order-scope.php',
            ),
        ],
        'seed_packages' => $seeds,
        'closure_packages' => $closure,
        'always_run_groups' => $alwaysRunGroups,
        'selected_groups' => $selectedGroups,
        'selected_paths' => $selectedPaths,
        'selected_files' => count($selectedPaths),
        'inventory_files' => count($inventory),
    ]);
}

function ros_diff(string $root, string $base, string $head): string
{
    if (trim($base) === '') {
        throw new RosScopeFailure('no diff base was supplied');
    }

    $process = proc_open(
        [$root . '/bin/git', '-C', $root, 'diff', '--name-status', '-z', $base . '...' . $head],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RosScopeFailure('git diff could not be started');
    }
    $output = (string) stream_get_contents($pipes[1]);
    $error = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RosScopeFailure('git diff failed: ' . ($error !== '' ? $error : 'unreachable base'));
    }

    return $output;
}
