<?php

declare(strict_types=1);

/**
 * Shared vendor/ freshness precondition for repository gate scripts (#2926,
 * #2972).
 *
 * This file is deliberately dependency-free and never loads the application
 * autoloader. It reads Composer metadata and the generated array declarations
 * only, so it still works when vendor/ is stale or bound to another checkout.
 *
 * Checks, in order:
 *   0. vendor/autoload.php exists                                  -> composer install
 *   1. composer.lock and vendor/composer/installed.json are readable JSON
 *   2. locked and installed package sets are identical             -> composer install
 *   3. every package version and source/dist reference agrees      -> composer install
 *   4. generated compatibility and runtime-static PSR-4 maps carry
 *      every root/locked declaration                               -> composer dump-autoload
 *   5. candidate-owned root/path-package PSR-4, optimized classmap,
 *      and autoload-files entries resolve to this checkout         -> composer install
 */

const VENDOR_FRESHNESS_EXIT_CODE = 3;

/**
 * @return array{what: string, detail: string, fix: string}|null null when fresh
 */
function vendor_freshness_problem(string $root): ?array
{
    $root = vendor_freshness_lexical_path($root);
    $canonicalRoot = vendor_freshness_canonical_path($root);
    if ($canonicalRoot === null) {
        return vendor_freshness_stale('the repository root cannot be resolved', sprintf('Could not resolve %s.', $root), 'restore the checkout');
    }

    $autoloadPath = "{$root}/vendor/autoload.php";
    if (!is_file($autoloadPath)) {
        return vendor_freshness_stale('vendor/ is not installed', 'vendor/autoload.php does not exist.', 'composer install');
    }
    $canonicalAutoload = vendor_freshness_canonical_path($autoloadPath);
    if ($canonicalAutoload === null || !vendor_freshness_path_is_within($canonicalAutoload, $canonicalRoot)) {
        return vendor_freshness_source_problem(
            $root,
            'vendor/autoload.php',
            $canonicalAutoload ?? '(unresolved)',
            $canonicalRoot,
        );
    }

    $lock = vendor_freshness_read_json("{$root}/composer.lock", 'composer.lock');
    if (isset($lock['__problem'])) {
        return $lock['__problem'];
    }
    $installed = vendor_freshness_read_json("{$root}/vendor/composer/installed.json", 'vendor/composer/installed.json');
    if (isset($installed['__problem'])) {
        return $installed['__problem'];
    }

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
            sprintf('e.g. %s — composer.lock has them but vendor/ does not (un-installed path-repo, new dependency, or a --no-dev install).', implode(', ', array_slice($missing, 0, 5))),
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

    $ownership = vendor_freshness_first_party_ownership($root, $canonicalRoot, $composer, $lockedByName);
    if ($ownership['problem'] !== null) {
        return $ownership['problem'];
    }

    $runtime = vendor_freshness_runtime_maps($root, $canonicalRoot);
    if ($runtime['problem'] !== null) {
        return $runtime['problem'];
    }
    $compatibility = vendor_freshness_compatibility_maps(
        $root,
        $canonicalRoot,
        $ownership['files'] !== [] || $runtime['files'] !== [],
    );
    if ($compatibility['problem'] !== null) {
        return $compatibility['problem'];
    }

    // Preserve the original completeness check for every locked dependency,
    // including third-party packages. Runtime-static completeness is checked
    // independently because Composer loads that plane through autoload_real.
    $declaredNamespaces = array_merge(
        vendor_freshness_psr4_namespaces($composer, ['autoload', 'autoload-dev'], 'composer.json'),
        ...array_map(
            static fn(array $locked): array => vendor_freshness_psr4_namespaces($locked, ['autoload'], (string) $locked['name']),
            array_values($lockedByName),
        ),
    );
    foreach (['autoload_psr4.php' => $compatibility['psr4'], 'autoload_static.php' => $runtime['psr4']] as $mapLabel => $psr4) {
        $missingNamespaces = [];
        foreach ($declaredNamespaces as [$namespace, $declaredBy]) {
            if (!isset($psr4[$namespace])) {
                $missingNamespaces[] = "{$namespace} ({$declaredBy})";
            }
        }
        if ($missingNamespaces !== []) {
            return vendor_freshness_stale(
                sprintf('%d declared PSR-4 namespace(s) missing from %s', count($missingNamespaces), $mapLabel),
                sprintf('e.g. %s — the manifest declares them but the dumped autoloader does not.', implode(', ', array_slice($missingNamespaces, 0, 5))),
                'composer dump-autoload',
            );
        }
    }

    foreach (['compatibility' => $compatibility, 'runtime-static' => $runtime] as $plane => $maps) {
        $problem = vendor_freshness_first_party_map_problem($root, $canonicalRoot, $ownership, $maps, $plane);
        if ($problem !== null) {
            return $problem;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $manifest
 * @param list<string> $sections
 * @return list<array{string, string}> [namespace, declared-by label]
 */
function vendor_freshness_psr4_namespaces(array $manifest, array $sections, string $declaredBy): array
{
    $namespaces = [];
    foreach ($sections as $section) {
        $psr4 = $manifest[$section]['psr-4'] ?? null;
        if (!is_array($psr4)) {
            continue;
        }
        foreach (array_keys($psr4) as $namespace) {
            if (is_string($namespace)) {
                $namespaces[] = [$namespace, $declaredBy];
            }
        }
    }

    return $namespaces;
}

/**
 * Build candidate-owned declarations. A path package is classified from its
 * lexical dist URL before realpath is consulted, so a candidate path symlinked
 * to another checkout cannot disguise itself as an external dependency.
 *
 * @param array<string, mixed> $composer
 * @param array<string, array<string, mixed>> $lockedByName
 * @return array{
 *   psr4: array<string, array{owners: list<string>, expected: list<string>}>,
 *   files: array<string, array{owner: string, expected: string}>,
 *   third_party_psr4: list<string>,
 *   problem: array{what: string, detail: string, fix: string}|null
 * }
 */
function vendor_freshness_first_party_ownership(string $root, string $canonicalRoot, array $composer, array $lockedByName): array
{
    $psr4 = [];
    $files = [];
    $thirdPartyPsr4 = [];
    $rootName = is_string($composer['name'] ?? null) ? $composer['name'] : 'root-package';
    vendor_freshness_add_owned_manifest($psr4, $files, $composer, ['autoload', 'autoload-dev'], 'composer.json', $rootName, $root);

    foreach ($lockedByName as $name => $package) {
        $dist = is_array($package['dist'] ?? null) ? $package['dist'] : [];
        $lexicalPackageRoot = null;
        if (($dist['type'] ?? null) === 'path' && is_string($dist['url'] ?? null)) {
            $declaredUrl = str_replace('\\', '/', $dist['url']);
            $lexicalPackageRoot = vendor_freshness_lexical_path(
                vendor_freshness_path_is_absolute($declaredUrl) ? $declaredUrl : $root . '/' . $declaredUrl,
            );
        }
        if ($lexicalPackageRoot === null || !vendor_freshness_path_is_within($lexicalPackageRoot, $root)) {
            foreach (vendor_freshness_psr4_namespaces($package, ['autoload'], $name) as [$namespace]) {
                $thirdPartyPsr4[] = $namespace;
            }
            continue;
        }
        $canonicalPackageRoot = vendor_freshness_canonical_path($lexicalPackageRoot);
        if ($canonicalPackageRoot === null || !vendor_freshness_path_is_within($canonicalPackageRoot, $canonicalRoot)) {
            return [
                'psr4' => $psr4,
                'files' => $files,
                'third_party_psr4' => array_values(array_unique($thirdPartyPsr4)),
                'problem' => vendor_freshness_stale(
                    sprintf('candidate-owned path package %s resolves outside this checkout', $name),
                    sprintf('composer.lock declares %s at %s, which resolves to %s; expected a source path under %s.', $name, $lexicalPackageRoot, $canonicalPackageRoot ?? '(unresolved)', $canonicalRoot),
                    'restore the candidate path package, then run composer install',
                ),
            ];
        }
        vendor_freshness_add_owned_manifest($psr4, $files, $package, ['autoload'], $name, $name, $lexicalPackageRoot);
    }

    return [
        'psr4' => $psr4,
        'files' => $files,
        'third_party_psr4' => array_values(array_unique($thirdPartyPsr4)),
        'problem' => null,
    ];
}

/**
 * @param array<string, array{owners: list<string>, expected: list<string>}> $psr4
 * @param array<string, array{owner: string, expected: string}> $files
 * @param array<string, mixed> $manifest
 * @param list<string> $sections
 */
function vendor_freshness_add_owned_manifest(array &$psr4, array &$files, array $manifest, array $sections, string $label, string $packageName, string $base): void
{
    foreach ($sections as $section) {
        $autoload = is_array($manifest[$section] ?? null) ? $manifest[$section] : [];
        $declaredPsr4 = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
        foreach ($declaredPsr4 as $namespace => $paths) {
            if (!is_string($namespace)) {
                continue;
            }
            $pathList = is_array($paths) ? $paths : [$paths];
            foreach ($pathList as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $psr4[$namespace] ??= ['owners' => [], 'expected' => []];
                if (!in_array($label, $psr4[$namespace]['owners'], true)) {
                    $psr4[$namespace]['owners'][] = $label;
                }
                $psr4[$namespace]['expected'][] = vendor_freshness_declared_path($base, $path);
            }
        }

        $declaredFiles = is_array($autoload['files'] ?? null) ? $autoload['files'] : [];
        foreach ($declaredFiles as $path) {
            if (!is_string($path)) {
                continue;
            }
            $files[md5($packageName . ':' . $path)] = [
                'owner' => $label,
                'expected' => vendor_freshness_declared_path($base, $path),
            ];
        }
    }
}

/**
 * @return array{psr4: array<mixed>, classmap: array<mixed>, files: array<mixed>, problem: array{what: string, detail: string, fix: string}|null}
 */
function vendor_freshness_compatibility_maps(string $root, string $canonicalRoot, bool $requireFiles): array
{
    $maps = [];
    foreach (['psr4' => 'autoload_psr4.php', 'classmap' => 'autoload_classmap.php'] as $kind => $filename) {
        $loaded = vendor_freshness_require_array("{$root}/vendor/composer/{$filename}", "vendor/composer/{$filename}", $root, $canonicalRoot);
        if ($loaded['problem'] !== null) {
            return ['psr4' => [], 'classmap' => [], 'files' => [], 'problem' => $loaded['problem']];
        }
        $maps[$kind] = $loaded['map'];
    }

    $filesPath = "{$root}/vendor/composer/autoload_files.php";
    if (is_file($filesPath)) {
        $loaded = vendor_freshness_require_array($filesPath, 'vendor/composer/autoload_files.php', $root, $canonicalRoot);
        if ($loaded['problem'] !== null) {
            return ['psr4' => [], 'classmap' => [], 'files' => [], 'problem' => $loaded['problem']];
        }
        $maps['files'] = $loaded['map'];
    } elseif ($requireFiles) {
        return [
            'psr4' => [],
            'classmap' => [],
            'files' => [],
            'problem' => vendor_freshness_stale(
                'the autoload-files compatibility map is missing',
                'vendor/composer/autoload_files.php does not exist, but the runtime or a candidate-owned manifest declares autoload files.',
                'composer dump-autoload',
            ),
        ];
    } else {
        $maps['files'] = [];
    }

    return ['psr4' => $maps['psr4'], 'classmap' => $maps['classmap'], 'files' => $maps['files'], 'problem' => null];
}

/**
 * Read Composer's authoritative runtime-static maps without invoking
 * vendor/autoload.php or loading any application file. autoload_real.php is
 * checked to bind the declaration class Composer will actually initialize.
 *
 * @return array{psr4: array<mixed>, classmap: array<mixed>, files: array<mixed>, problem: array{what: string, detail: string, fix: string}|null}
 */
function vendor_freshness_runtime_maps(string $root, string $canonicalRoot): array
{
    $realPath = "{$root}/vendor/composer/autoload_real.php";
    $staticPath = "{$root}/vendor/composer/autoload_static.php";
    foreach ([$realPath, $staticPath] as $path) {
        if (!is_file($path)) {
            return ['psr4' => [], 'classmap' => [], 'files' => [], 'problem' => vendor_freshness_stale('the runtime autoloader is not generated', sprintf('%s does not exist.', $path), 'composer dump-autoload')];
        }
        $canonical = vendor_freshness_canonical_path($path);
        if ($canonical === null || !vendor_freshness_path_is_within($canonical, $canonicalRoot)) {
            return ['psr4' => [], 'classmap' => [], 'files' => [], 'problem' => vendor_freshness_metadata_problem($root, $path, $canonical ?? '(unresolved)', $canonicalRoot)];
        }
    }

    $real = file_get_contents($realPath);
    $static = file_get_contents($staticPath);
    if ($real === false || $static === false
        || preg_match('/namespace\\s+Composer\\\\Autoload\\s*;/', $static) !== 1
        || preg_match('/class\\s+(ComposerStaticInit[A-Za-z0-9_]+)/', $static, $matches) !== 1) {
        return ['psr4' => [], 'classmap' => [], 'files' => [], 'problem' => vendor_freshness_stale('the generated runtime autoloader is corrupt', 'Could not identify the Composer static-map class.', 'composer dump-autoload')];
    }
    $class = 'Composer\\Autoload\\' . $matches[1];
    if (!str_contains($real, '\\' . $class . '::getInitializer')) {
        return ['psr4' => [], 'classmap' => [], 'files' => [], 'problem' => vendor_freshness_stale('the generated runtime autoloader is incoherent', sprintf('autoload_real.php does not initialize %s from autoload_static.php.', $class), 'composer dump-autoload')];
    }

    $loaded = vendor_freshness_static_class_data($staticPath, $class);
    if ($loaded['problem'] !== null) {
        return ['psr4' => [], 'classmap' => [], 'files' => [], 'problem' => $loaded['problem']];
    }
    $canonicalDeclaration = is_string($loaded['declared_at']) ? vendor_freshness_canonical_path($loaded['declared_at']) : null;
    $canonicalStatic = vendor_freshness_canonical_path($staticPath);
    if ($canonicalDeclaration !== $canonicalStatic) {
        return ['psr4' => [], 'classmap' => [], 'files' => [], 'problem' => vendor_freshness_stale(
            'the runtime static-map class was loaded from another checkout',
            sprintf('%s is already declared by %s; expected %s.', $class, $canonicalDeclaration ?? '(unknown source)', $canonicalStatic ?? $staticPath),
            vendor_freshness_install_fix($root),
        )];
    }

    $vars = $loaded['vars'];
    $psr4 = is_array($vars['prefixDirsPsr4'] ?? null) ? $vars['prefixDirsPsr4'] : [];
    $classmap = is_array($vars['classMap'] ?? null) ? $vars['classMap'] : [];
    $files = is_array($vars['files'] ?? null) ? $vars['files'] : [];
    if ($files !== [] && !str_contains($real, '\\' . $class . '::$files')) {
        return ['psr4' => [], 'classmap' => [], 'files' => [], 'problem' => vendor_freshness_stale('the generated runtime autoloader is incoherent', sprintf('autoload_real.php does not load the %s autoload-files map.', $class), 'composer dump-autoload')];
    }

    return ['psr4' => $psr4, 'classmap' => $classmap, 'files' => $files, 'problem' => null];
}

/**
 * Load Composer's generated static-map class without declaring a new class in
 * the caller. Several freshness callers intentionally load vendor/autoload.php
 * after this precondition, and Composer requires autoload_static.php rather
 * than require_once-ing it. A child PHP process keeps that later load valid.
 *
 * An already-declared class is inspected in place so a caller polluted by a
 * donor checkout still reports the declaration source rather than hiding it.
 *
 * @return array{declared_at: string|null, vars: array<mixed>, problem: array{what: string, detail: string, fix: string}|null}
 */
function vendor_freshness_static_class_data(string $staticPath, string $class): array
{
    if (class_exists($class, false)) {
        try {
            $declaredAt = (new \ReflectionClass($class))->getFileName();
            $vars = get_class_vars($class);
        } catch (\Throwable $exception) {
            return ['declared_at' => null, 'vars' => [], 'problem' => vendor_freshness_stale('the generated runtime autoloader is corrupt', sprintf('%s could not be inspected: %s', $class, $exception->getMessage()), 'composer dump-autoload')];
        }

        return ['declared_at' => is_string($declaredAt) ? $declaredAt : null, 'vars' => $vars, 'problem' => null];
    }

    $probe = <<<'PHP'
try {
    $path = $argv[1] ?? '';
    $class = $argv[2] ?? '';
    require $path;
    if (!class_exists($class, false)) {
        throw new RuntimeException(sprintf('%s did not declare %s.', $path, $class));
    }
    $declaredAt = (new ReflectionClass($class))->getFileName();
    echo json_encode([
        'declared_at' => is_string($declaredAt) ? $declaredAt : null,
        'vars' => get_class_vars($class),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage());
    exit(1);
}
PHP;
    $descriptorSpec = [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open(
        [PHP_BINARY, '-r', $probe, $staticPath, $class],
        $descriptorSpec,
        $pipes,
        null,
        null,
        ['bypass_shell' => true, 'suppress_errors' => true],
    );
    if (!is_resource($process)) {
        return ['declared_at' => null, 'vars' => [], 'problem' => vendor_freshness_stale('the generated runtime autoloader could not be inspected', sprintf('Could not start %s to inspect %s.', PHP_BINARY, $staticPath), 'composer dump-autoload')];
    }

    $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : false;
    $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : false;
    foreach ([1, 2] as $index) {
        if (isset($pipes[$index]) && is_resource($pipes[$index])) {
            fclose($pipes[$index]);
        }
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_string($stdout)) {
        $diagnostic = is_string($stderr) && trim($stderr) !== '' ? trim($stderr) : sprintf('probe exited %d', $exitCode);

        return ['declared_at' => null, 'vars' => [], 'problem' => vendor_freshness_stale('the generated runtime autoloader is corrupt', sprintf('%s could not be read in isolation: %s', $staticPath, substr($diagnostic, 0, 512)), 'composer dump-autoload')];
    }

    try {
        /** @var mixed $decoded */
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
        return ['declared_at' => null, 'vars' => [], 'problem' => vendor_freshness_stale('the generated runtime autoloader is corrupt', sprintf('%s returned invalid inspection data: %s', $staticPath, $exception->getMessage()), 'composer dump-autoload')];
    }
    if (!is_array($decoded) || !array_key_exists('declared_at', $decoded) || !is_array($decoded['vars'] ?? null)) {
        return ['declared_at' => null, 'vars' => [], 'problem' => vendor_freshness_stale('the generated runtime autoloader is corrupt', sprintf('%s returned incomplete inspection data.', $staticPath), 'composer dump-autoload')];
    }

    return [
        'declared_at' => is_string($decoded['declared_at']) ? $decoded['declared_at'] : null,
        'vars' => $decoded['vars'],
        'problem' => null,
    ];
}

/**
 * @param array{psr4: array<string, array{owners: list<string>, expected: list<string>}>, files: array<string, array{owner: string, expected: string}>, third_party_psr4: list<string>} $ownership
 * @param array{psr4: array<mixed>, classmap: array<mixed>, files: array<mixed>} $maps
 * @return array{what: string, detail: string, fix: string}|null
 */
function vendor_freshness_first_party_map_problem(string $root, string $canonicalRoot, array $ownership, array $maps, string $plane): ?array
{
    /** @var array<string, list<string>> $canonicalPsr4 */
    $canonicalPsr4 = [];
    foreach ($ownership['psr4'] as $namespace => $declaration) {
        foreach ($declaration['expected'] as $expected) {
            $canonicalExpected = vendor_freshness_canonical_psr4_path($expected);
            if ($canonicalExpected === null || !vendor_freshness_path_is_within($canonicalExpected, $canonicalRoot)) {
                return vendor_freshness_source_problem($root, sprintf('%s declared by %s', $expected, implode(', ', $declaration['owners'])), $canonicalExpected ?? '(unresolved)', $canonicalRoot);
            }
            $canonicalPsr4[$namespace][] = $canonicalExpected;
        }
    }

    foreach ($maps['psr4'] as $namespace => $mappedPaths) {
        if (!is_string($namespace)) {
            continue;
        }
        $ownedNamespace = vendor_freshness_owned_namespace(
            $namespace,
            array_keys($canonicalPsr4),
            $ownership['third_party_psr4'],
        );
        if ($ownedNamespace === null) {
            continue;
        }
        $declaration = $ownership['psr4'][$ownedNamespace];
        $actualPaths = $mappedPaths;
        $actualPaths = is_array($actualPaths) ? $actualPaths : [$actualPaths];
        if ($actualPaths === []) {
            return vendor_freshness_stale(
                sprintf('%s PSR-4 mapping for %s has no source path', $plane, $namespace),
                sprintf('%s owns %s through %s, but its generated path list is empty.', implode(', ', $declaration['owners']), $namespace, $ownedNamespace),
                'composer dump-autoload',
            );
        }
        foreach ($actualPaths as $actual) {
            if (!is_string($actual)) {
                return vendor_freshness_stale(
                    sprintf('%s PSR-4 mapping for %s is corrupt', $plane, $namespace),
                    sprintf('Expected a generated path string; observed %s.', get_debug_type($actual)),
                    'composer dump-autoload',
                );
            }
            $canonical = vendor_freshness_canonical_psr4_path($actual);
            if ($canonical === null || !vendor_freshness_path_is_within($canonical, $canonicalRoot)) {
                return vendor_freshness_source_problem($root, sprintf('%s PSR-4 %s (%s)', $plane, $namespace, implode(', ', $declaration['owners'])), $canonical ?? $actual, $canonicalRoot);
            }
        }
    }

    foreach ($maps['classmap'] as $class => $actual) {
        if (!is_string($class)) {
            continue;
        }
        $matchedNamespace = vendor_freshness_owned_namespace($class, array_keys($canonicalPsr4), $ownership['third_party_psr4']);
        if ($matchedNamespace === null) {
            continue;
        }
        if (!is_string($actual)) {
            return vendor_freshness_stale(
                sprintf('%s classmap entry for %s is corrupt', $plane, $class),
                sprintf('Expected a generated path string; observed %s.', get_debug_type($actual)),
                'composer dump-autoload',
            );
        }
        $canonical = vendor_freshness_canonical_path($actual);
        if ($canonical === null || !vendor_freshness_path_is_within($canonical, $canonicalRoot)) {
            return vendor_freshness_source_problem(
                $root,
                sprintf('%s classmap %s (owned by PSR-4 %s)', $plane, $class, $matchedNamespace),
                $canonical ?? $actual,
                $canonicalRoot,
            );
        }
    }

    foreach ($ownership['files'] as $identifier => $declaration) {
        $expected = vendor_freshness_canonical_path($declaration['expected']);
        $actual = $maps['files'][$identifier] ?? null;
        if ($expected === null || !vendor_freshness_path_is_within($expected, $canonicalRoot)) {
            return vendor_freshness_source_problem($root, sprintf('%s declared by %s', $declaration['expected'], $declaration['owner']), $expected ?? '(unresolved)', $canonicalRoot);
        }
        if (!is_string($actual)) {
            return vendor_freshness_stale(
                sprintf('%s autoload-file %s is missing or corrupt', $plane, $identifier),
                sprintf('%s declares %s, but the generated map has %s.', $declaration['owner'], $declaration['expected'], get_debug_type($actual)),
                'composer dump-autoload',
            );
        }
        $canonical = vendor_freshness_canonical_path($actual);
        if ($canonical === null || !vendor_freshness_path_is_within($canonical, $canonicalRoot)) {
            return vendor_freshness_source_problem(
                $root,
                sprintf('%s autoload-file %s (%s)', $plane, $identifier, $declaration['owner']),
                $canonical ?? $actual,
                $canonicalRoot,
            );
        }
    }

    return null;
}

/** @param list<string> $namespaces */
function vendor_freshness_longest_namespace(string $class, array $namespaces): ?string
{
    $matches = array_values(array_filter($namespaces, static fn(string $namespace): bool => $namespace !== '' && str_starts_with($class, $namespace)));
    if ($matches === []) {
        return null;
    }
    usort($matches, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

    return $matches[0];
}

/**
 * @param list<string> $ownedNamespaces
 * @param list<string> $thirdPartyNamespaces
 */
function vendor_freshness_owned_namespace(string $symbol, array $ownedNamespaces, array $thirdPartyNamespaces): ?string
{
    $owned = vendor_freshness_longest_namespace($symbol, $ownedNamespaces);
    if ($owned === null) {
        return null;
    }
    $thirdParty = vendor_freshness_longest_namespace($symbol, $thirdPartyNamespaces);

    return $thirdParty !== null && strlen($thirdParty) > strlen($owned) ? null : $owned;
}

/**
 * @return array{map: array<mixed>, problem: array{what: string, detail: string, fix: string}|null}
 */
function vendor_freshness_require_array(string $path, string $label, string $root, string $canonicalRoot): array
{
    if (!is_file($path)) {
        return ['map' => [], 'problem' => vendor_freshness_stale('the autoloader is not generated', sprintf('%s does not exist.', $label), 'composer dump-autoload')];
    }
    $canonical = vendor_freshness_canonical_path($path);
    if ($canonical === null || !vendor_freshness_path_is_within($canonical, $canonicalRoot)) {
        return ['map' => [], 'problem' => vendor_freshness_metadata_problem($root, $path, $canonical ?? '(unresolved)', $canonicalRoot)];
    }
    try {
        /** @var mixed $map */
        $map = require $path;
    } catch (\Throwable $exception) {
        return ['map' => [], 'problem' => vendor_freshness_stale('the generated autoloader is corrupt', sprintf('%s could not be read: %s', $label, $exception->getMessage()), 'composer dump-autoload')];
    }
    if (!is_array($map)) {
        return ['map' => [], 'problem' => vendor_freshness_stale('the generated autoloader is corrupt', sprintf('%s did not return an array.', $label), 'composer dump-autoload')];
    }

    return ['map' => $map, 'problem' => null];
}

/**
 * @return array{what: string, detail: string, fix: string}
 */
function vendor_freshness_source_problem(string $root, string $subject, string $actual, string $expected): array
{
    return vendor_freshness_stale(
        'first-party autoload source resolves outside this checkout',
        sprintf('%s resolves to %s; expected %s. Repair only this candidate vendor/path-package; do not regenerate a donor checkout.', $subject, $actual, $expected),
        vendor_freshness_install_fix($root),
    );
}

/** @return array{what: string, detail: string, fix: string} */
function vendor_freshness_metadata_problem(string $root, string $subject, string $actual, string $expected): array
{
    return vendor_freshness_stale(
        'generated Composer metadata resolves outside this checkout',
        sprintf('%s resolves to %s; expected a path under %s. Restore candidate-local generated metadata before installing; do not regenerate a donor checkout.', $subject, $actual, $expected),
        vendor_freshness_metadata_fix($root),
    );
}

function vendor_freshness_install_fix(string $root): string
{
    return is_link(rtrim($root, '/') . '/vendor') ? 'unlink vendor && composer install' : 'composer install';
}

function vendor_freshness_metadata_fix(string $root): string
{
    $root = rtrim($root, '/');
    if (is_link($root . '/vendor')) {
        return 'unlink vendor && composer install';
    }
    if (is_link($root . '/vendor/composer')) {
        return 'unlink vendor/composer && composer install';
    }

    return 'restore vendor/composer as a candidate-local directory, then run composer install';
}

function vendor_freshness_lexical_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $prefix = str_starts_with($path, '//') ? '//' : (str_starts_with($path, '/') ? '/' : '');
    if (preg_match('/^[A-Za-z]:\\//', $path) === 1) {
        $prefix = substr($path, 0, 3);
        $path = substr($path, 3);
    } elseif ($prefix !== '') {
        $path = substr($path, strlen($prefix));
    }
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..' && $parts !== [] && end($parts) !== '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return $parts === [] ? $prefix : $prefix . implode('/', $parts);
}

function vendor_freshness_path_is_absolute(string $path): bool
{
    $path = str_replace('\\', '/', $path);

    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\//', $path) === 1;
}

function vendor_freshness_canonical_path(string $path): ?string
{
    $resolved = realpath($path);

    return $resolved === false ? null : vendor_freshness_lexical_path($resolved);
}

/**
 * Composer preserves PSR-4 directory declarations even when the leaf does not
 * exist. Resolve the closest existing ancestor and project the missing suffix
 * without weakening symlink containment. A dangling symlink is unverifiable.
 */
function vendor_freshness_canonical_psr4_path(string $path): ?string
{
    $cursor = str_replace('\\', '/', $path);
    $suffix = [];
    while (true) {
        $resolved = vendor_freshness_canonical_path($cursor);
        if ($resolved !== null) {
            return $suffix === [] ? $resolved : vendor_freshness_lexical_path($resolved . '/' . implode('/', $suffix));
        }
        $trimmed = rtrim($cursor, '/');
        if ($trimmed === '') {
            return null;
        }
        if (is_link($trimmed)) {
            return null;
        }
        $parent = str_replace('\\', '/', dirname($trimmed));
        if ($parent === $trimmed || $parent === '' || $parent === '.') {
            return null;
        }
        array_unshift($suffix, basename($trimmed));
        $cursor = $parent;
    }
}

function vendor_freshness_declared_path(string $base, string $path): string
{
    $path = str_replace('\\', '/', $path);
    if (vendor_freshness_path_is_absolute($path)) {
        return $path;
    }

    return rtrim(str_replace('\\', '/', $base), '/') . '/' . ltrim($path, '/');
}

function vendor_freshness_path_is_within(string $path, string $root): bool
{
    $path = rtrim(vendor_freshness_lexical_path($path), '/');
    $root = rtrim(vendor_freshness_lexical_path($root), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }

    return $path === $root || str_starts_with($path, $root . '/');
}

/**
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

/** @return array{what: string, detail: string, fix: string} */
function vendor_freshness_stale(string $what, string $detail, string $fix): array
{
    return ['what' => $what, 'detail' => $detail, 'fix' => $fix];
}

/** @return array<string, mixed> */
function vendor_freshness_read_json(string $path, string $label): array
{
    if (!is_file($path)) {
        return ['__problem' => vendor_freshness_stale("{$label} is missing", sprintf('%s does not exist at %s.', $label, $path), 'composer install')];
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
