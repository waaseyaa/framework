# Targeted random-order selection and sharding — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `ci/random-order` run a fail-closed, evidence-backed selection of the PHPUnit inventory across a three-way shard matrix on pull requests, while `main` and a new nightly workflow retain complete-suite proof.

**Architecture:** A new selector (`bin/select-random-order-scope`) reads a git diff and emits a deterministic JSON *selection document* — either `mode: full` or `mode: targeted` with an explicit path set derived from changed packages, their consumer closure, and an always-run set. `bin/build-phpunit-shards` gains `--only` to turn that document into a timing-balanced three-shard plan, and `bin/test-random-order` gains `--plan`/`--shard` to replay a saved plan. `ci.yml` prepares plan and dependencies once per run and fans out; `ci/random-order` survives as a strict aggregator because it is a required status context.

**Tech Stack:** PHP 8.5 CLI scripts (no framework, no Composer dependencies), PHPUnit 13.1, GitHub Actions, `bin/lib/*.php` shared-library convention.

**Spec:** [docs/specs/ci-test-selection.md](../../specs/ci-test-selection.md)

## Global Constraints

- PHP 8.5+, `declare(strict_types=1)` in every new file.
- Selector and library code must not require Composer autoload — `bin/` scripts run before `vendor/` exists in the prepare job. Use `require_once` of `bin/lib/*.php`, matching `bin/lib/s1-roster.php`.
- **An internal failure is never an error exit.** The selector exits 0 with `mode: full` and a recorded `fallback_reason`. Exit 2 is reserved for usage errors.
- All emitted JSON uses `JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`; every list is sorted so output is byte-identical across repeated runs.
- Shard count is **3**. `ci/random-order` job name and required-context name must not change.
- Tests run as `php -d memory_limit=1G vendor/bin/phpunit --testsuite Architecture --no-coverage`. Never pass `-v`.
- Every commit adds a `## [Unreleased]` CHANGELOG entry and passes `php bin/check-pr-preflight`.
- No release, split, deployment, or ruleset change is in scope.

---

### Task 1: Scope manifest and manifest validation

**Files:**
- Create: `tools/random-order-scope-manifest.json`
- Create: `bin/lib/random-order-scope.php`
- Test: `tests/Architecture/RandomOrderScopeSelectorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `RosScopeFailure` (exception carrying a fallback reason), `ros_load_manifest(string $root): array` returning `['protected' => list<string>, 'prefixes' => array<string, list<string>>]` where the value is that prefix's seed packages.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RandomOrderScopeSelectorTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        require_once $this->repoRoot . '/bin/lib/random-order-scope.php';
    }

    #[Test]
    public function it_loads_the_committed_manifest(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);

        self::assertContains('phpunit.xml.dist', $manifest['protected']);
        self::assertContains('composer.lock', $manifest['protected']);
        self::assertContains('bin/select-random-order-scope', $manifest['protected']);
        self::assertArrayHasKey('docs/', $manifest['prefixes']);
        self::assertSame(['cli'], $manifest['prefixes']['bin/waaseyaa']);
    }

    #[Test]
    public function it_rejects_a_prefix_that_shadows_another(): void
    {
        $root = $this->fixtureRoot(['prefixes' => [
            ['path' => 'tools/', 'rationale' => 'repo tooling'],
            ['path' => 'tools/phpstan/', 'rationale' => 'shadowed'],
        ]]);

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/ambiguous manifest prefix/');
        ros_load_manifest($root);
    }

    #[Test]
    public function it_rejects_an_entry_without_a_rationale(): void
    {
        $root = $this->fixtureRoot(['prefixes' => [['path' => 'docs/']]]);

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/rationale/');
        ros_load_manifest($root);
    }

    #[Test]
    public function it_rejects_a_seed_naming_an_absent_package(): void
    {
        $root = $this->fixtureRoot(['prefixes' => [
            ['path' => 'docs/', 'rationale' => 'docs', 'seeds' => ['no-such-package']],
        ]]);

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/absent package/');
        ros_load_manifest($root);
    }

    /** @param array{prefixes: list<array<string, mixed>>} $document */
    private function fixtureRoot(array $document): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('ros', true);
        mkdir($root . '/tools', 0o777, true);
        mkdir($root . '/packages/cli', 0o777, true);
        file_put_contents(
            $root . '/packages/cli/composer.json',
            json_encode(['name' => 'waaseyaa/cli'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $root . '/tools/random-order-scope-manifest.json',
            json_encode(['schema_version' => 1, 'protected' => [], ...$document], JSON_THROW_ON_ERROR),
        );

        return $root;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderScopeSelectorTest --no-coverage`
Expected: FAIL — `bin/lib/random-order-scope.php` does not exist.

- [ ] **Step 3: Write the manifest**

```json
{
    "schema_version": 1,
    "_comment": "Fail-closed path classification for bin/select-random-order-scope (docs/specs/ci-test-selection.md §3.3). A changed path matching no entry here and no packages/*/ or tests/** rule selects the complete inventory. Every prefixes entry needs a rationale; protected entries force the complete inventory.",
    "protected": [
        "bin/select-random-order-scope",
        "bin/build-phpunit-shards",
        "bin/test-random-order",
        "bin/lib/random-order-scope.php",
        "tools/random-order-scope-manifest.json",
        "tools/phpunit-timings.json",
        "phpunit.xml.dist",
        "phpunit.xml",
        "composer.json",
        "composer.lock",
        ".github/workflows/ci.yml",
        ".github/workflows/nightly.yml"
    ],
    "prefixes": [
        {"path": "docs/", "rationale": "documentation cannot change PHP behaviour; spec drift is gated separately"},
        {"path": "kitty-specs/", "rationale": "read-only historical mission artifacts"},
        {"path": ".github/", "rationale": "workflow shape is proven by tests/Architecture, which is always run; ci.yml and nightly.yml are in protected"},
        {"path": "tools/", "rationale": "repo tooling is proven by tests/Architecture, which is always run; selector inputs are in protected"},
        {"path": "bin/", "rationale": "gate scripts are proven by tests/Architecture, which is always run; selector scripts are in protected"},
        {"path": "bin/waaseyaa", "rationale": "the CLI entry point is additionally exercised by the cli package suite", "seeds": ["cli"]}
    ]
}
```

Note: `bin/waaseyaa` is longer than `bin/`, so longest-prefix-wins resolves it; the shadowing check exempts an exact-file entry under a directory entry (see Step 4).

- [ ] **Step 4: Write the library**

```php
<?php

declare(strict_types=1);

final class RosScopeFailure extends RuntimeException
{
}

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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderScopeSelectorTest --no-coverage`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add tools/random-order-scope-manifest.json bin/lib/random-order-scope.php tests/Architecture/RandomOrderScopeSelectorTest.php
git commit -m "feat(ci): add fail-closed scope manifest for random-order selection (#2404)"
```

---

### Task 2: Diff acquisition and path classification

**Files:**
- Modify: `bin/lib/random-order-scope.php`
- Test: `tests/Architecture/RandomOrderScopeSelectorTest.php`

**Interfaces:**
- Consumes: `ros_load_manifest()`, `RosScopeFailure` from Task 1.
- Produces:
  - `ros_parse_name_status(string $raw): list<string>` — NUL-delimited `git diff --name-status -z` output to a sorted, de-duplicated path list, expanding `R`/`C` records to **both** paths.
  - `ros_classify(list<string> $paths, array $manifest, string $root): array{seeds: list<string>, full_reason: ?string}`.

- [ ] **Step 1: Write the failing tests**

```php
    #[Test]
    public function it_expands_rename_records_to_both_paths(): void
    {
        $raw = "R096\0packages/node/src/Old.php\0packages/media/src/New.php\0"
            . "M\0packages/api/src/Kept.php\0"
            . "D\0packages/user/src/Gone.php\0";

        self::assertSame([
            'packages/api/src/Kept.php',
            'packages/media/src/New.php',
            'packages/node/src/Old.php',
            'packages/user/src/Gone.php',
        ], ros_parse_name_status($raw));
    }

    #[Test]
    public function a_rename_across_packages_seeds_both_packages(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);
        $result = ros_classify(
            ['packages/node/src/Old.php', 'packages/media/src/New.php'],
            $manifest,
            $this->repoRoot,
        );

        self::assertNull($result['full_reason']);
        self::assertSame(['media', 'node'], $result['seeds']);
    }

    #[Test]
    public function selector_inputs_force_the_complete_inventory(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);

        foreach ([
            'bin/select-random-order-scope',
            'bin/build-phpunit-shards',
            'bin/test-random-order',
            'tools/random-order-scope-manifest.json',
            'phpunit.xml.dist',
            'composer.json',
            'composer.lock',
            '.github/workflows/ci.yml',
            '.github/workflows/nightly.yml',
            'packages/api/composer.json',
        ] as $path) {
            $result = ros_classify([$path], $manifest, $this->repoRoot);
            self::assertNotNull($result['full_reason'], "{$path} must force the complete inventory.");
        }
    }

    #[Test]
    public function an_unknown_root_path_forces_the_complete_inventory(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);

        foreach (['scripts/deploy.sh', '.gitignore', 'public/index.php', 'defaults/ingestion.yaml'] as $path) {
            $result = ros_classify([$path], $manifest, $this->repoRoot);
            self::assertSame("unclassified path: {$path}", $result['full_reason']);
        }
    }

    #[Test]
    public function a_package_without_a_parsable_manifest_forces_the_complete_inventory(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);
        $result = ros_classify(['packages/no-such-package/src/A.php'], $manifest, $this->repoRoot);

        self::assertSame(
            'package is absent from the dependency graph: no-such-package',
            $result['full_reason'],
        );
    }

    #[Test]
    public function bounded_prefixes_seed_only_what_they_declare(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);

        self::assertSame([], ros_classify(['docs/specs/api-layer.md'], $manifest, $this->repoRoot)['seeds']);
        self::assertSame([], ros_classify(['bin/check-dead-code'], $manifest, $this->repoRoot)['seeds']);
        self::assertSame(['cli'], ros_classify(['bin/waaseyaa'], $manifest, $this->repoRoot)['seeds']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderScopeSelectorTest --no-coverage`
Expected: FAIL — `ros_parse_name_status` undefined.

- [ ] **Step 3: Implement**

```php
/** @return list<string> */
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderScopeSelectorTest --no-coverage`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add bin/lib/random-order-scope.php tests/Architecture/RandomOrderScopeSelectorTest.php
git commit -m "feat(ci): classify changed paths with rename and self-protection handling (#2404)"
```

---

### Task 3: Dependency graph and consumer closure

**Files:**
- Modify: `bin/lib/random-order-scope.php`
- Test: `tests/Architecture/RandomOrderScopeSelectorTest.php`

**Interfaces:**
- Consumes: `RosScopeFailure`.
- Produces:
  - `ros_package_graph(string $root): array{reverse: array<string, list<string>>, psr4: array<string, string>}` — `reverse` maps a package to its direct consumers over `require` **and** `require-dev`; `psr4` maps a namespace prefix (no trailing slash) to a package directory.
  - `ros_closure(array $reverse, list<string> $seeds): list<string>`.

- [ ] **Step 1: Write the failing tests**

```php
    #[Test]
    public function closure_follows_consumers_transitively(): void
    {
        $graph = ros_package_graph($this->repoRoot);

        $leaf = ros_closure($graph['reverse'], ['genealogy']);
        self::assertSame(['genealogy'], $leaf);

        $hub = ros_closure($graph['reverse'], ['entity']);
        self::assertContains('entity', $hub);
        self::assertContains('node', $hub);
        self::assertContains('api', $hub);
        self::assertGreaterThan(50, count($hub), 'entity is a hub; its consumer closure is large.');
    }

    #[Test]
    public function closure_includes_require_dev_consumers(): void
    {
        $graph = ros_package_graph($this->repoRoot);
        $closure = ros_closure($graph['reverse'], ['testing']);

        self::assertContains('testing', $closure);
        self::assertGreaterThan(1, count($closure), 'packages/testing is consumed via require-dev.');
    }

    #[Test]
    public function closure_terminates_on_the_accepted_two_cycles(): void
    {
        $baseline = (string) file_get_contents($this->repoRoot . '/tools/package-layers-cycle-baseline.txt');
        self::assertStringContainsString('access', $baseline);

        $graph = ros_package_graph($this->repoRoot);
        $closure = ros_closure($graph['reverse'], ['access']);

        self::assertContains('access', $closure);
        self::assertContains('entity', $closure);
        self::assertSame($closure, array_values(array_unique($closure)));
    }

    #[Test]
    public function a_malformed_package_manifest_is_a_scope_failure(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('rosg', true);
        mkdir($root . '/packages/broken', 0o777, true);
        file_put_contents($root . '/packages/broken/composer.json', '{ not json');

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/packages\/broken\/composer\.json/');
        ros_package_graph($root);
    }

    #[Test]
    public function a_duplicate_package_name_is_a_scope_failure(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('rosd', true);
        foreach (['one', 'two'] as $dir) {
            mkdir($root . '/packages/' . $dir, 0o777, true);
            file_put_contents(
                $root . '/packages/' . $dir . '/composer.json',
                json_encode(['name' => 'waaseyaa/same'], JSON_THROW_ON_ERROR),
            );
        }

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/duplicate package name/');
        ros_package_graph($root);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderScopeSelectorTest --no-coverage`
Expected: FAIL — `ros_package_graph` undefined.

- [ ] **Step 3: Implement**

```php
/**
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderScopeSelectorTest --no-coverage`
Expected: PASS (15 tests).

- [ ] **Step 5: Commit**

```bash
git add bin/lib/random-order-scope.php tests/Architecture/RandomOrderScopeSelectorTest.php
git commit -m "feat(ci): compute consumer closure over require and require-dev edges (#2404)"
```

---

### Task 4: Inventory, attribution, atomic expansion, selection document

**Files:**
- Modify: `bin/lib/random-order-scope.php`
- Create: `bin/select-random-order-scope`
- Test: `tests/Architecture/RandomOrderScopeSelectorTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-3.
- Produces:
  - `ros_group_of(string $path): string` — `packages/<name>` | `tests/<TopDir>` | `tests`.
  - `ros_inventory(string $root): array<string, string>` — inventory path to suite name.
  - `ros_attribute(string $path, array $psr4, string $root): ?list<string>` — `null` when unattributable.
  - `ros_select(string $root, string $base, string $head, ?string $forcedReason): array` — the full selection document of spec §4.
  - `bin/select-random-order-scope` CLI emitting that document.

- [ ] **Step 1: Write the failing tests**

```php
    #[Test]
    public function groups_follow_the_shard_planner(): void
    {
        self::assertSame('packages/api', ros_group_of('packages/api/tests/Unit/ATest.php'));
        self::assertSame('tests/Integration', ros_group_of('tests/Integration/PhaseN/BTest.php'));
        self::assertSame('tests/Architecture', ros_group_of('tests/Architecture/CTest.php'));
    }

    #[Test]
    public function unattributable_files_pin_their_group_into_the_always_run_set(): void
    {
        $document = ros_select($this->repoRoot, 'HEAD', 'HEAD', null);

        self::assertContains('tests/Architecture', $document['always_run_groups']);
        self::assertContains('tests/Integration', $document['always_run_groups']);
    }

    #[Test]
    public function attribution_is_ambiguity_intolerant(): void
    {
        $graph = ros_package_graph($this->repoRoot);

        $attributed = ros_attribute(
            'tests/Integration/GraphQL/GraphQlSchemaTest.php',
            $graph['psr4'],
            $this->repoRoot,
        );
        self::assertNotNull($attributed);
        self::assertNotSame([], $attributed);

        self::assertNull(
            ros_attribute('tests/Architecture/CiContractOrderingTest.php', $graph['psr4'], $this->repoRoot),
            'A repo-state contract test imports no package namespace and must be unattributable.',
        );
    }

    #[Test]
    public function a_selected_file_expands_to_its_complete_group(): void
    {
        $document = ros_select($this->repoRoot, 'HEAD', 'HEAD', null);
        $inventory = ros_inventory($this->repoRoot);

        foreach ($document['selected_groups'] as $group) {
            $expected = array_values(array_filter(
                array_keys($inventory),
                static fn (string $path): bool => ros_group_of($path) === $group,
            ));
            foreach ($expected as $path) {
                self::assertContains(
                    $path,
                    $document['selected_paths'],
                    "Group {$group} was selected, so {$path} must be selected with it.",
                );
            }
        }
    }

    #[Test]
    public function the_document_records_its_inputs_and_is_deterministic(): void
    {
        $first = ros_select($this->repoRoot, 'HEAD', 'HEAD', null);
        $second = ros_select($this->repoRoot, 'HEAD', 'HEAD', null);

        self::assertSame($first, $second);
        foreach (['manifest', 'composer_graph', 'phpunit_config', 'selector'] as $key) {
            self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $first['digests'][$key]);
        }
        self::assertSame(1, $first['schema_version']);
        self::assertGreaterThan(0, $first['inventory_files']);
    }

    #[Test]
    public function an_absent_base_selects_the_complete_inventory_without_erroring(): void
    {
        $result = $this->runSelector(['--base=']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        $document = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('full', $document['mode']);
        self::assertNotNull($document['fallback_reason']);
        self::assertSame($document['inventory_files'], $document['selected_files']);
    }

    #[Test]
    public function an_unreachable_base_selects_the_complete_inventory(): void
    {
        $result = $this->runSelector(['--base=0000000000000000000000000000000000000000']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        $document = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('full', $document['mode']);
    }

    #[Test]
    public function an_empty_diff_selects_the_complete_inventory(): void
    {
        $result = $this->runSelector(['--base=HEAD']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        $document = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('full', $document['mode']);
        self::assertSame('the diff is empty', $document['fallback_reason']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code: int, output: string}
     */
    private function runSelector(array $arguments): array
    {
        $command = [PHP_BINARY, $this->repoRoot . '/bin/select-random-order-scope', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repoRoot);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'output' => $output];
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderScopeSelectorTest --no-coverage`
Expected: FAIL — `ros_group_of` undefined.

- [ ] **Step 3: Implement the library additions**

```php
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

/** @return array<string, string> inventory path => suite name */
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

    return [
        'schema_version' => 1,
        'mode' => $reason === null ? 'targeted' : 'full',
        'fallback_reason' => $reason,
        'base_sha' => $base,
        'head_sha' => $head,
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
    ];
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
```

- [ ] **Step 4: Write the CLI**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/random-order-scope.php';

$options = getopt('', ['base::', 'head::', 'root::', 'mode::']);
$root = is_string($options['root'] ?? null) && $options['root'] !== ''
    ? rtrim((string) $options['root'], '/')
    : dirname(__DIR__);
$head = is_string($options['head'] ?? null) && $options['head'] !== '' ? (string) $options['head'] : 'HEAD';
$mode = $options['mode'] ?? null;

if ($mode !== null && $mode !== 'full') {
    fwrite(STDERR, "Usage: php bin/select-random-order-scope [--base=<sha>] [--head=<sha>] [--mode=full]\n");
    exit(2);
}

$forced = $mode === 'full' ? 'the complete inventory was requested' : null;
$base = is_string($options['base'] ?? null) ? (string) $options['base'] : '';

try {
    $document = ros_select($root, $base, $head, $forced);
} catch (Throwable $exception) {
    // Never fail the lane on a selector defect: emit a complete-inventory decision.
    $document = [
        'schema_version' => 1,
        'mode' => 'full',
        'fallback_reason' => 'selector failure: ' . $exception->getMessage(),
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
}

echo json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
exit(0);
```

- [ ] **Step 5: Make it executable and run the tests**

```bash
chmod +x bin/select-random-order-scope
php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderScopeSelectorTest --no-coverage
```
Expected: PASS (24 tests).

- [ ] **Step 6: Verify the measured baseline still holds**

```bash
php bin/select-random-order-scope --base=origin/main | \
  php -r '$d=json_decode(stream_get_contents(STDIN),true); printf("%s %d/%d %s\n",$d["mode"],$d["selected_files"],$d["inventory_files"],$d["fallback_reason"]??"-");'
```
Expected: a `full` decision naming a selector input, because this branch modifies `bin/select-random-order-scope`. That is the self-protection rule working.

- [ ] **Step 7: Commit**

```bash
git add bin/select-random-order-scope bin/lib/random-order-scope.php tests/Architecture/RandomOrderScopeSelectorTest.php
git commit -m "feat(ci): emit deterministic fail-closed random-order selection documents (#2404)"
```

---

### Task 5: Shard planner `--only`

**Files:**
- Modify: `bin/build-phpunit-shards`
- Test: `tests/Architecture/PhpUnitShardPlannerTest.php`

**Interfaces:**
- Consumes: the selection document from Task 4.
- Produces: a plan document gaining `selection`, `seed`, `phpunit_version`, per-shard `suites` (suite name to path list) and `empty` flags. `mode` becomes `targeted` when `--only` is given.

- [ ] **Step 1: Write the failing tests**

```php
    #[Test]
    public function onlyRestrictsThenReexpandsToCompleteGroups(): void
    {
        $selection = $this->writeSelection(['packages/genealogy/tests/Unit/OneTest.php']);
        $plan = $this->plan(['--only=' . $selection]);

        self::assertSame('targeted', $plan['mode']);
        $paths = $this->allPaths($plan);
        foreach ($paths as $path) {
            self::assertStringStartsWith('packages/genealogy/', $path);
        }
        self::assertGreaterThan(1, count($paths), 'The planner re-expands to the complete group.');
    }

    #[Test]
    public function onlyRefusesAPathAbsentFromTheInventory(): void
    {
        $selection = $this->writeSelection(['packages/genealogy/tests/Unit/GhostTest.php']);
        $result = $this->run(['--only=' . $selection]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('absent from the discovered inventory', $result['error']);
    }

    #[Test]
    public function everyPathResolvesToExactlyOneSuite(): void
    {
        $plan = $this->plan([]);
        foreach ($plan['include'] as $shard) {
            $fromSuites = [];
            foreach ($shard['suites'] as $paths) {
                array_push($fromSuites, ...$paths);
            }
            sort($fromSuites);
            $declared = $shard['paths'] === '' ? [] : explode("\n", $shard['paths']);
            sort($declared);
            self::assertSame($declared, $fromSuites, 'Suite partition must be total and disjoint.');
        }
    }

    #[Test]
    public function anEmptyShardIsDeclaredRatherThanDropped(): void
    {
        $selection = $this->writeSelection(['packages/genealogy/tests/Unit/OneTest.php']);
        $plan = $this->plan(['--only=' . $selection, '--shards=3']);

        self::assertCount(3, $plan['include'], 'Every matrix leg must be present.');
        $empty = array_values(array_filter($plan['include'], static fn (array $s): bool => $s['empty'] === true));
        self::assertNotSame([], $empty);
        foreach ($empty as $shard) {
            self::assertSame('', $shard['paths']);
            self::assertSame(0, $shard['test_files']);
        }
    }

    #[Test]
    public function thePlanRecordsItsProvenance(): void
    {
        $plan = $this->plan(['--seed=2241']);

        self::assertSame(2241, $plan['seed']);
        self::assertMatchesRegularExpression('/^\d+\.\d+/', $plan['phpunit_version']);
        self::assertSame(1, $plan['selection']['schema_version']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter PhpUnitShardPlannerTest --no-coverage`
Expected: FAIL — `--only` is not recognised, `suites`/`empty`/`seed` keys are absent.

- [ ] **Step 3: Implement**

Extend the `getopt` call to `['root::', 'timings:', 'shards::', 'pretty', 'only::', 'seed::']`, then after the existing `$files` discovery and before grouping, insert:

```php
require_once __DIR__ . '/lib/random-order-scope.php';

$mode = 'full';
$selection = null;
$onlyPath = $options['only'] ?? '';
if (is_string($onlyPath) && $onlyPath !== '') {
    try {
        $selection = json_decode((string) file_get_contents($onlyPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Unable to read the selection document: {$exception->getMessage()}\n");
        exit(2);
    }
    if (!is_array($selection) || ($selection['schema_version'] ?? null) !== 1) {
        fwrite(STDERR, "The selection document must use schema_version 1.\n");
        exit(2);
    }
    foreach ($selection['selected_paths'] as $path) {
        if (!isset($files[$path])) {
            fwrite(STDERR, "Selected path is absent from the discovered inventory: {$path}\n");
            exit(2);
        }
    }
    if ($selection['mode'] === 'targeted') {
        $mode = 'targeted';
        // Re-expand to complete groups: the planner owns group membership.
        $selectedGroups = [];
        foreach ($selection['selected_paths'] as $path) {
            $selectedGroups[ros_group_of($path)] = true;
        }
        foreach (array_keys($files) as $path) {
            if (!isset($selectedGroups[ros_group_of($path)])) {
                unset($files[$path]);
            }
        }
    }
}

if ($files === []) {
    fwrite(STDERR, "Selection retained no test files.\n");
    exit(2);
}

// Suite assignment must be total and unique.
$suiteOf = ros_inventory($root);
foreach (array_keys($files) as $path) {
    if (!isset($suiteOf[$path])) {
        fwrite(STDERR, "Test file resolves to no PHPUnit suite: {$path}\n");
        exit(2);
    }
}
```

Change `$actualShardCount = min($shardCount, count($groups));` to `$actualShardCount = $shardCount;` so empty legs survive, then in the matrix loop add the new keys:

```php
    $suites = [];
    foreach ($shard['paths'] as $path) {
        $suites[$suiteOf[$path]][] = $path;
    }
    ksort($suites);

    $matrix[] = [
        'id' => $shard['id'],
        'paths' => implode("\n", $shard['paths']),
        'suites' => $suites,
        'empty' => $shard['paths'] === [],
        'test_files' => count($shard['paths']),
        'expected_seconds' => round($shard['expected_seconds'], 6),
        'fallback_files' => $shard['fallback_files'],
    ];
```

and extend the document with `'mode' => $mode`, `'selection' => $selection`, `'seed' => isset($options['seed']) ? (int) $options['seed'] : null`, and:

```php
    'phpunit_version' => trim((string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/vendor/bin/phpunit') . ' --version 2>/dev/null'
    )) !== '' ? (preg_match('/PHPUnit\s+(\S+)/', (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/vendor/bin/phpunit') . ' --version'
    ), $m) === 1 ? $m[1] : 'unknown') : 'unknown',
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter PhpUnitShardPlannerTest --no-coverage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/build-phpunit-shards tests/Architecture/PhpUnitShardPlannerTest.php
git commit -m "feat(ci): plan targeted shards with total suite assignment and explicit empty legs (#2404)"
```

---

### Task 6: Runner `--plan` / `--shard`

**Files:**
- Modify: `bin/test-random-order`
- Test: `tests/Architecture/RandomOrderRunnerTest.php`

**Interfaces:**
- Consumes: the plan document from Task 5.
- Produces: `bin/test-random-order --plan=<path> --shard=<id>` executing one PHPUnit process per non-empty suite of that shard, with the plan's seed.

- [ ] **Step 1: Write the failing tests**

```php
    #[Test]
    public function it_replays_a_saved_plan_shard_per_suite(): void
    {
        $plan = $this->writePlan(3, 2241, [
            1 => ['Unit' => ['packages/genealogy/tests/Unit/OneTest.php'], 'Architecture' => ['tests/Architecture/CiContractOrderingTest.php']],
            2 => [],
            3 => [],
        ]);

        $result = $this->run(['--plan=' . $plan, '--shard=1', '--list-suites']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('PHPUnit random-order seed: 2241', $result['output']);
        self::assertStringContainsString('PHPUnit random-order suite: Unit', $result['output']);
        self::assertStringContainsString('PHPUnit random-order suite: Architecture', $result['output']);
    }

    #[Test]
    public function an_empty_shard_succeeds_without_invoking_phpunit(): void
    {
        $plan = $this->writePlan(3, 2241, [1 => ['Unit' => ['packages/genealogy/tests/Unit/OneTest.php']], 2 => [], 3 => []]);

        $result = $this->run(['--plan=' . $plan, '--shard=2']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('shard 2 is empty', $result['output']);
        self::assertStringNotContainsString('Available test suite', $result['output']);
    }

    #[Test]
    public function an_unknown_shard_is_rejected(): void
    {
        $plan = $this->writePlan(3, 2241, [1 => [], 2 => [], 3 => []]);

        $result = $this->run(['--plan=' . $plan, '--shard=9']);

        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('absent from the exact plan', $result['output']);
    }

    #[Test]
    public function a_malformed_plan_is_rejected(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';
        file_put_contents($path, '{ not json');

        $result = $this->run(['--plan=' . $path, '--shard=1']);

        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('plan', $result['output']);
    }

    /** @param array<int, array<string, list<string>>> $shards */
    private function writePlan(int $count, int $seed, array $shards): string
    {
        $include = [];
        for ($id = 1; $id <= $count; ++$id) {
            $suites = $shards[$id] ?? [];
            $paths = [];
            foreach ($suites as $suitePaths) {
                array_push($paths, ...$suitePaths);
            }
            $include[] = [
                'id' => $id,
                'paths' => implode("\n", $paths),
                'suites' => $suites,
                'empty' => $paths === [],
                'test_files' => count($paths),
                'expected_seconds' => 0.0,
                'fallback_files' => 0,
            ];
        }
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';
        file_put_contents($path, json_encode(
            ['schema_version' => 1, 'mode' => 'targeted', 'seed' => $seed, 'include' => $include],
            JSON_THROW_ON_ERROR,
        ));

        return $path;
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderRunnerTest --no-coverage`
Expected: FAIL — `--plan` is treated as a PHPUnit argument.

- [ ] **Step 3: Implement**

In the argument loop, capture the two new options before the `$phpunitArguments[] = $argument;` fallthrough:

```php
    if (str_starts_with($argument, '--plan=')) {
        $planPath = substr($argument, strlen('--plan='));
        continue;
    }
    if (str_starts_with($argument, '--shard=')) {
        $shardId = (int) substr($argument, strlen('--shard='));
        continue;
    }
```

After seed resolution, insert the plan branch, replacing the `$suites` derivation:

```php
$planSuites = null;
if ($planPath !== null) {
    try {
        $plan = json_decode((string) file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        fwrite(STDERR, "The shard plan is unreadable: {$exception->getMessage()}\n");
        exit(2);
    }
    if (!is_array($plan) || ($plan['schema_version'] ?? null) !== 1 || !is_array($plan['include'] ?? null)) {
        fwrite(STDERR, "The shard plan must use schema_version 1.\n");
        exit(2);
    }
    $seed = (string) ($plan['seed'] ?? $seed);
    $shard = null;
    foreach ($plan['include'] as $candidate) {
        if (($candidate['id'] ?? null) === $shardId) {
            $shard = $candidate;
            break;
        }
    }
    if ($shard === null) {
        fwrite(STDERR, "Shard id {$shardId} is absent from the exact plan.\n");
        exit(2);
    }
    if (($shard['empty'] ?? false) === true) {
        echo "PHPUnit random-order seed: {$seed}\n";
        echo "shard {$shardId} is empty; no PHPUnit process is required.\n";
        exit(0);
    }
    $planSuites = $shard['suites'];
}
```

Then in the execution loop, when `$planSuites !== null`, iterate `$planSuites` and append that suite's explicit paths **instead of** `--testsuite`:

```php
if ($planSuites !== null) {
    foreach ($planSuites as $suite => $paths) {
        echo "PHPUnit random-order suite: {$suite}\n";
        $command = [
            PHP_BINARY, '-d', 'memory_limit=1G', $root . '/vendor/bin/phpunit',
            '--no-coverage', '--order-by=random', '--random-order-seed=' . $seed,
            ...$paths, ...$phpunitArguments,
        ];
        flush();
        passthru(implode(' ', array_map(escapeshellarg(...), $command)), $exitCode);
        if ($exitCode !== 0) {
            exit($exitCode);
        }
    }
    exit(0);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter RandomOrderRunnerTest --no-coverage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/test-random-order tests/Architecture/RandomOrderRunnerTest.php
git commit -m "feat(ci): replay random-order shards from a saved plan (#2404)"
```

---

### Task 7: `ci.yml` topology, dependency artifact, aggregator

**Files:**
- Modify: `.github/workflows/ci.yml:382-413`
- Modify: `tests/Architecture/CiSingleExecutionProofTest.php`
- Modify: `tests/Architecture/CiContractOrderingTest.php`

**Interfaces:**
- Consumes: Tasks 4-6.
- Produces: jobs `prepare-random-order-plan`, `ci-random-order-shard` (matrix 1-3), and the `ci/random-order` aggregator.

- [ ] **Step 1: Write the failing tests**

```php
    #[Test]
    public function randomOrderPreparesOnceAndFansOutToThreeShards(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        self::assertStringContainsString('prepare-random-order-plan:', $workflow);
        self::assertStringContainsString('php bin/select-random-order-scope', $workflow);
        self::assertStringContainsString('--shards=3', $workflow);
        self::assertStringContainsString('id: [1, 2, 3]', $workflow);
        self::assertStringContainsString('name: ci/random-order', $workflow);
        self::assertStringContainsString('bin/test-random-order --plan=', $workflow);
    }

    #[Test]
    public function theRandomOrderAggregatorRefusesIncompleteShardEvidence(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        $job = $this->job($workflow, 'ci-random-order', 'ci-package-isolation');

        self::assertStringContainsString('needs: [prepare-random-order-plan, ci-random-order-shard]', $job);
        self::assertStringContainsString('PLAN_RESULT', $job);
        self::assertStringContainsString('SHARD_RESULT', $job);
        self::assertStringContainsString('test "$PLAN_RESULT" = success', $job);
        self::assertStringContainsString('test "$SHARD_RESULT" = success', $job);
        self::assertStringContainsString('test "$SHARD_COUNT" -eq 3', $job);
    }

    #[Test]
    public function shardsVerifyTheRunScopedDependencyArtifact(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        self::assertStringContainsString('sha256sum --check', $workflow);
        self::assertStringContainsString('composer check-platform-reqs', $workflow);
        self::assertStringNotContainsString('restore-keys: composer-v2-', $workflow);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter 'CiSingleExecutionProofTest|CiContractOrderingTest' --no-coverage`
Expected: FAIL — `prepare-random-order-plan:` absent.

- [ ] **Step 3: Replace the `ci-random-order` job**

Replace `.github/workflows/ci.yml:382-413` with:

```yaml
  prepare-random-order-plan:
    name: Prepare random-order plan
    runs-on: ubuntu-24.04
    needs: [support-contract, spec-drift]
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          ref: ${{ inputs.sha }}
          fetch-depth: 0

      - name: Set up PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2.37.2
        with:
          php-version: '8.5'
          extensions: pdo_sqlite, sqlite3, mbstring, xml
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Select the bounded random-order scope
        env:
          SELECTION_BASE: ${{ github.event.pull_request.base.sha || '' }}
        run: |
          mkdir -p build/ci
          if test "${{ github.event_name }}" = pull_request; then
            php bin/select-random-order-scope --base="$SELECTION_BASE" > build/ci/random-order-scope.json
          else
            php bin/select-random-order-scope --mode=full > build/ci/random-order-scope.json
          fi
          php -r '$d = json_decode(file_get_contents("build/ci/random-order-scope.json"), true, 512, JSON_THROW_ON_ERROR); printf("::notice title=Random-order scope::%s %d/%d files%s%s", $d["mode"], $d["selected_files"], $d["inventory_files"], $d["fallback_reason"] === null ? "" : " — " . $d["fallback_reason"], PHP_EOL);'

      - name: Build the deterministic shard plan
        run: |
          seed=$(( (GITHUB_RUN_ID % 2147483647) + 1 ))
          echo "::notice title=PHPUnit random-order seed::$seed"
          php bin/build-phpunit-shards --shards=3 --timings=tools/phpunit-timings.json \
            --only=build/ci/random-order-scope.json --seed="$seed" > build/ci/random-order-plan.json

      - name: Prepare the run-scoped dependency archive
        run: |
          tar --create --file build/ci/vendor.tar vendor
          sha256sum build/ci/vendor.tar > build/ci/vendor.tar.sha256
          php -r '$h = hash_file("sha256", "vendor/composer/installed.php"); file_put_contents("build/ci/installed.sha256", $h . PHP_EOL);'

      - name: Retain the exact plan and dependencies
        uses: actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7.0.1
        with:
          name: random-order-plan
          path: |
            build/ci/random-order-scope.json
            build/ci/random-order-plan.json
            build/ci/vendor.tar
            build/ci/vendor.tar.sha256
            build/ci/installed.sha256
          if-no-files-found: error
          retention-days: 30

  ci-random-order-shard:
    name: random-order shard ${{ matrix.id }}
    runs-on: ubuntu-24.04
    needs: [prepare-random-order-plan]
    strategy:
      fail-fast: false
      matrix:
        id: [1, 2, 3]
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          ref: ${{ inputs.sha }}

      - name: Set up PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2.37.2
        with:
          php-version: '8.5'
          extensions: pdo_sqlite, sqlite3, mbstring, xml
          coverage: none

      - name: Download the exact plan and dependencies
        uses: actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c # v8.0.1
        with:
          name: random-order-plan
          path: build/ci

      # Exact-checkout binding: a wrong-head or tampered archive is discarded,
      # never trusted. vendor/waaseyaa/* are relative symlinks into packages/,
      # so a stale archive can carry links to packages this checkout lacks.
      - name: Restore dependencies with integrity proof
        run: |
          set -euo pipefail
          restore_failed=0
          ( cd build/ci && sha256sum --check vendor.tar.sha256 ) || restore_failed=1
          if test "$restore_failed" -eq 0; then
            tar --extract --file build/ci/vendor.tar || restore_failed=1
          fi
          if test "$restore_failed" -eq 0; then
            composer check-platform-reqs --no-interaction || restore_failed=1
          fi
          if test "$restore_failed" -eq 0; then
            expected=$(cat build/ci/installed.sha256)
            actual=$(php -r 'echo hash_file("sha256", "vendor/composer/installed.php");')
            test "$expected" = "$actual" || restore_failed=1
          fi
          if test "$restore_failed" -eq 0; then
            php -r '$bad = []; foreach (glob("vendor/waaseyaa/*") as $link) { if (!is_file($link . "/composer.json")) { $bad[] = $link; } } if ($bad !== []) { fwrite(STDERR, "dangling: " . implode(",", $bad) . PHP_EOL); exit(1); }' || restore_failed=1
          fi
          if test "$restore_failed" -ne 0; then
            echo "::warning title=Dependency archive rejected::falling back to a locked install"
            rm -rf vendor
            composer install --no-interaction --prefer-dist --no-progress
          fi

      - name: Execute the shard in replayable random order
        run: php bin/test-random-order --plan=build/ci/random-order-plan.json --shard=${{ matrix.id }}

  ci-random-order:
    name: ci/random-order
    runs-on: ubuntu-24.04
    needs: [prepare-random-order-plan, ci-random-order-shard]
    if: always()
    steps:
      # `if: always()` alone would publish success after skipped shards, so the
      # aggregator asserts planning, shard count, and every shard result.
      - name: Require complete random-order evidence
        env:
          PLAN_RESULT: ${{ needs.prepare-random-order-plan.result }}
          SHARD_RESULT: ${{ needs.ci-random-order-shard.result }}
          SHARD_COUNT: 3
        run: |
          test "$PLAN_RESULT" = success
          test "$SHARD_COUNT" -eq 3
          test "$SHARD_RESULT" = success
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -d memory_limit=1G vendor/bin/phpunit --testsuite Architecture --no-coverage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/ci.yml tests/Architecture/CiSingleExecutionProofTest.php tests/Architecture/CiContractOrderingTest.php
git commit -m "feat(ci): shard random-order over a selected scope with a verified dependency archive (#2404)"
```

---

### Task 8: Nightly complete proof

**Files:**
- Create: `.github/workflows/nightly.yml`
- Create: `tests/Architecture/NightlyRandomOrderProofTest.php`

**Interfaces:**
- Consumes: `composer test:random` (unchanged full-inventory path).
- Produces: a scheduled `nightly/random-order-full` job.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class NightlyRandomOrderProofTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/.github/workflows/nightly.yml';
        self::assertFileExists($path);
        $this->workflow = (string) file_get_contents($path);
    }

    #[Test]
    public function it_runs_the_complete_unsharded_suite_on_a_schedule(): void
    {
        self::assertStringContainsString('schedule:', $this->workflow);
        self::assertStringContainsString('workflow_dispatch:', $this->workflow);
        self::assertStringContainsString('composer test:random', $this->workflow);
        self::assertStringNotContainsString('--shard=', $this->workflow);
        self::assertStringNotContainsString('--only=', $this->workflow);
    }

    #[Test]
    public function it_supports_manual_seed_replay_and_guards_concurrency(): void
    {
        self::assertStringContainsString('seed:', $this->workflow);
        self::assertStringContainsString('TEST_RANDOM_SEED', $this->workflow);
        self::assertStringContainsString('concurrency:', $this->workflow);
    }

    #[Test]
    public function it_retains_failure_evidence_and_holds_no_deployment_authority(): void
    {
        self::assertStringContainsString('if: failure()', $this->workflow);
        self::assertStringContainsString('upload-artifact', $this->workflow);

        foreach (['deploy', 'release', 'split', 'packagist', 'rsync', 'ssh'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                strtolower($this->workflow),
                "nightly.yml must hold no {$forbidden} authority.",
            );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter NightlyRandomOrderProofTest --no-coverage`
Expected: FAIL — `nightly.yml` does not exist.

- [ ] **Step 3: Write the workflow**

```yaml
name: Nightly

# The complete, UNSHARDED random-order proof. ci/random-order shards on pull
# requests, which cannot observe cross-shard ordering interactions; this job
# restores that coverage every night. It holds no deployment authority.
on:
  schedule:
    - cron: '0 5 * * *'
  workflow_dispatch:
    inputs:
      seed:
        description: 'Replay a specific random-order seed (empty = date-derived)'
        required: false
        type: string

concurrency:
  group: nightly-${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: false

permissions:
  contents: read

jobs:
  random-order-full:
    name: nightly/random-order-full
    runs-on: ubuntu-24.04
    timeout-minutes: 45
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1

      - name: Set up PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2.37.2
        with:
          php-version: '8.5'
          extensions: pdo_sqlite, sqlite3, mbstring, xml
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Run the complete suite in replayable random order
        env:
          SEED_INPUT: ${{ inputs.seed }}
        run: |
          seed="$SEED_INPUT"
          if test -z "$seed"; then
            seed=$(( ($(date -u +%Y%m%d) % 2147483647) + 1 ))
          fi
          echo "::notice title=Nightly random-order seed::$seed"
          echo "Replay: TEST_RANDOM_SEED=$seed composer test:random"
          mkdir -p build/logs
          TEST_RANDOM_SEED="$seed" composer test:random -- --log-junit build/logs/nightly-junit.xml

      - name: Upload failure evidence
        uses: actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7.0.1
        if: failure()
        with:
          name: nightly-random-order-evidence
          path: build/logs
          if-no-files-found: warn
          retention-days: 30
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -d memory_limit=1G vendor/bin/phpunit --filter NightlyRandomOrderProofTest --no-coverage`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/nightly.yml tests/Architecture/NightlyRandomOrderProofTest.php
git commit -m "feat(ci): add nightly complete unsharded random-order proof (#2404)"
```

---

### Task 9: Publication and acceptance evidence

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `docs/specs/ci-test-selection.md` (status line only, once green)

- [ ] **Step 1: Add the CHANGELOG entry**

```markdown
- **Targeted random-order proof (#2404):** `ci/random-order` now selects a
  fail-closed bounded scope on pull requests — changed packages plus their
  consumer closure over `require` and `require-dev`, expanded to complete
  test groups, unioned with an always-run set computed from files that cannot
  be attributed to a package — and executes it across three timing-balanced
  shards. Any unclassified path, selector-input change, malformed dependency
  graph, or ambiguous attribution selects the complete inventory. `main`
  retains the complete proof, and a new nightly workflow runs it unsharded to
  cover cross-shard ordering interactions. Dependencies are prepared once per
  run and verified against the exact checkout before reuse.
```

- [ ] **Step 2: Run the complete publication gates**

```bash
set -o pipefail
php bin/check-pr-preflight --full
php -d memory_limit=1G vendor/bin/phpunit --testsuite Unit --no-coverage
php -d memory_limit=1G vendor/bin/phpunit --testsuite Integration --no-coverage
php -d memory_limit=1G vendor/bin/phpunit --testsuite Architecture --no-coverage
```
Expected: all green. Do not proceed on any red.

- [ ] **Step 3: Open the pull request**

```bash
git push -u origin ci/2404-targeted-random-order
gh pr create --title "ci(#2404): targeted, sharded random-order proof with nightly complete coverage" --body "$(cat <<'BODY'
Implements the final bullet of #2404 slice 1 and slice 3's evidence-based
selection, per docs/specs/ci-test-selection.md.

Verification: bin/check-pr-preflight --full plus Unit, Integration, and
Architecture suites green on the exact pushed head.
BODY
)"
```

- [ ] **Step 4: Collect acceptance evidence**

Once the PR run, the post-merge `main` run, and one nightly run are green, gather at least 10 comparable runs:

```bash
gh run list --workflow ci.yml --branch main --limit 20 --json databaseId,conclusion,createdAt > /tmp/runs.json
for id in $(gh run list --workflow ci.yml --limit 12 --json databaseId --jq '.[].databaseId'); do
  gh api "repos/:owner/:repo/actions/runs/$id/jobs" \
    --jq '.jobs[] | select(.name | test("random-order|test-shard")) | [.name, .started_at, .completed_at] | @tsv'
done
```

Compute median and p95 critical-path time plus total runner-minutes before and after, then comment them on #2404. **Do not close #2404** — slices 2 and 5 remain open.

- [ ] **Step 5: Commit the status update**

```bash
git add CHANGELOG.md docs/specs/ci-test-selection.md
git commit -m "docs(ci): record targeted random-order acceptance evidence (#2404)"
```

---

## Self-Review

**Spec coverage:** §2 invariants 1-2 → Tasks 7-8; invariant 3 → Tasks 1-4; invariant 4 → Task 2; invariant 5 → Task 7; invariant 6 untouched. §3.1 → Task 2. §3.2 → Task 2. §3.3 → Tasks 1-2. §3.4 → Tasks 3-4. §3.5 → Task 4. §4 → Task 4. §5 → Task 5. §6 → Task 6. §7.1 → Task 7. §7.2 → Task 8. §7.3 → Task 7. §7.4 → Task 7. §8 → every task's test block. §9 → Task 9. §10 is a non-goal statement, no task needed.

**Type consistency:** `ros_group_of` is used identically in Tasks 4 and 5. `ros_inventory` returns path-to-suite in both Task 4 and Task 5's suite-assignment check. The plan's `suites`/`empty` keys written in Task 5 are exactly the keys read in Task 6. The selection document keys emitted in Task 4 are the keys consumed in Tasks 5 and 7.

**Known follow-ups, deliberately out of scope:** `bin/build-phpunit-shards` currently caps shards at `count($groups)`; Task 5 lifts that so empty legs survive, which also affects the existing `ci-test-shards` matrix — the Task 5 test `anEmptyShardIsDeclaredRatherThanDropped` covers the new behaviour, and `ci/unit-tests` already asserts every leg succeeded.
