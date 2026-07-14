<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Policy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for bin/check-composer-policy.
 *
 * Each test creates a minimal ephemeral fixture tree under sys_get_temp_dir(),
 * runs the live script via proc_open with ROOT_DIR injected, and asserts the
 * expected exit code and FAIL-ID output for violation cases.
 *
 * Because bin/lib/internal-version-sync.php exists in the repo, CP-NEW always
 * resolves the current git tag via Strategy A (PHP lib) and compares all
 * waaseyaa/* constraints in fixture package manifests against it. To keep
 * positive fixtures clean, we resolve the live tag once in setUpBeforeClass()
 * and use it as the internal constraint in every fixture that carries a
 * waaseyaa/* dependency. If no tag exists (fresh clone), the test is skipped.
 *
 * CP-NEW negative case (stale constraint detected) is deferred: it requires
 * a fixture git repo initialised with a specific tag so the version comparison
 * is deterministic without touching the live repo state. See #1384 follow-up.
 */
#[CoversNothing]
final class CheckComposerPolicyTest extends TestCase
{
    private const BIN = __DIR__ . '/../../../bin/check-composer-policy';

    /**
     * The current `^vX.Y.Z-pre.N` constraint resolved from the live repo tag,
     * or null when no tag exists. Set once in setUpBeforeClass().
     */
    private static ?string $liveConstraint = null;

    /** @var list<string> */
    private array $tempDirs = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Resolve the live git tag the same way bin/lib/internal-version-sync.php
        // does, so our fixtures carry a constraint that satisfies CP-NEW.
        $repoRoot = dirname(__DIR__, 3);
        $result = shell_exec(
            'git -C ' . escapeshellarg($repoRoot)
            . ' describe --tags --abbrev=0 --match="v*.*.*" 2>/dev/null',
        );
        if ($result === null || trim((string) $result) === '') {
            return; // No tags — tests requiring a constraint will be skipped.
        }
        $tag = ltrim(trim((string) $result), 'v');
        self::$liveConstraint = '^' . $tag;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }
        $this->tempDirs = [];
    }

    // ── CP001: config.sort-packages must be true ──────────────────────────────

    #[Test]
    public function cp001_passes_when_sort_packages_is_true(): void
    {
        // CP001 only needs sort-packages=true; no waaseyaa/* deps needed so
        // CP-NEW cannot fire.
        $dir = $this->makeFixture();

        [$exitCode] = $this->runScript($dir);
        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function cp001_fails_when_sort_packages_is_false(): void
    {
        $dir = $this->makeFixture(
            rootExtra: ['config' => ['sort-packages' => false]],
            pkgExtra: ['config' => ['sort-packages' => true]],
        );

        [$exitCode, $stdout] = $this->runScript($dir);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL [CP001]', $stdout);
    }

    // ── CP002: @dev forbidden in root and packages/* ──────────────────────────

    #[Test]
    public function cp002_passes_when_no_dev_constraints(): void
    {
        $this->requireLiveConstraint();
        $dir = $this->makeFixture(
            pkgRequire: ['waaseyaa/foundation' => self::$liveConstraint],
        );

        [$exitCode] = $this->runScript($dir);
        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function cp002_fails_when_package_uses_at_dev(): void
    {
        $dir = $this->makeFixture(
            pkgRequire: ['waaseyaa/foundation' => '@dev'],
        );

        [$exitCode, $stdout] = $this->runScript($dir);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL [CP002]', $stdout);
    }

    // ── CP003: wildcard internal constraints forbidden ────────────────────────

    #[Test]
    public function cp003_passes_when_no_wildcard_constraints(): void
    {
        $this->requireLiveConstraint();
        $dir = $this->makeFixture(
            pkgRequire: ['waaseyaa/foundation' => self::$liveConstraint],
        );

        [$exitCode] = $this->runScript($dir);
        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function cp003_fails_when_package_uses_wildcard_constraint(): void
    {
        $dir = $this->makeFixture(
            pkgRequire: ['waaseyaa/foundation' => '*'],
        );

        [$exitCode, $stdout] = $this->runScript($dir);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL [CP003]', $stdout);
    }

    // ── CP004: core runtime surface — debug/telescope/testing not in require ──

    #[Test]
    public function cp004_passes_when_core_does_not_require_debug_packages(): void
    {
        $this->requireLiveConstraint();
        $dir = $this->makeFixtureWithCore(
            coreRequire: [
                'php' => '>=8.5',
                'waaseyaa/foundation' => self::$liveConstraint,
            ],
        );

        [$exitCode] = $this->runScript($dir);
        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function cp004_fails_when_core_requires_debug_package(): void
    {
        // waaseyaa/debug in packages/core/composer.json require is forbidden.
        $this->requireLiveConstraint();
        $dir = $this->makeFixtureWithCore(
            coreRequire: [
                'php' => '>=8.5',
                'waaseyaa/foundation' => self::$liveConstraint,
                'waaseyaa/debug' => self::$liveConstraint,
            ],
        );

        [$exitCode, $stdout] = $this->runScript($dir);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL [CP004]', $stdout);
    }

    // ── CP005: tight internal floor ───────────────────────────────────────────

    #[Test]
    public function cp005_passes_when_constraint_has_pre_release_anchor(): void
    {
        $this->requireLiveConstraint();
        $dir = $this->makeFixture(
            pkgRequire: ['waaseyaa/foundation' => self::$liveConstraint],
        );

        [$exitCode] = $this->runScript($dir);
        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function cp005_fails_when_constraint_lacks_pre_release_anchor(): void
    {
        // ^0.1.0 has no alpha/beta/rc/dev anchor — tight-floor violation.
        // CP-NEW won't fire because ^0.1.0 lacks a pre-release anchor (the
        // script only compares constraints that already pass the tight-floor
        // shape check, to avoid double-reporting).
        $dir = $this->makeFixture(
            pkgRequire: ['waaseyaa/foundation' => '^0.1.0'],
        );

        [$exitCode, $stdout] = $this->runScript($dir);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL [CP005]', $stdout);
    }

    // ── CP006: self.version only valid in root composer.json ─────────────────

    #[Test]
    public function cp006_passes_when_self_version_is_used_only_in_root(): void
    {
        $this->requireLiveConstraint();
        // Root uses self.version (expected shape for sibling metapackages).
        $dir = $this->makeFixture(
            rootRequire: ['waaseyaa/foundation' => 'self.version'],
            pkgRequire: ['waaseyaa/foundation' => self::$liveConstraint],
        );

        [$exitCode] = $this->runScript($dir);
        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function cp006_fails_when_package_uses_self_version(): void
    {
        $dir = $this->makeFixture(
            pkgRequire: ['waaseyaa/foundation' => 'self.version'],
        );

        [$exitCode, $stdout] = $this->runScript($dir);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL [CP006]', $stdout);
    }

    // ── CP007: package path repositories and internal requirements agree ─────

    #[Test]
    public function cp007_fails_when_internal_requirement_has_no_path_repository(): void
    {
        $this->requireLiveConstraint();
        $dir = $this->makeFixture(
            pkgRequire: ['waaseyaa/foundation' => self::$liveConstraint],
            addPathRepositories: false,
        );

        [$exitCode, $stdout] = $this->runScript($dir);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL [CP007]', $stdout);
        self::assertStringContainsString('waaseyaa/foundation required without a matching path repository', $stdout);
    }

    #[Test]
    public function cp007_fails_when_path_repository_has_no_internal_requirement(): void
    {
        $dir = $this->makeFixture(
            pkgExtra: [
                'repositories' => [[
                    'type' => 'path',
                    'url' => '../unused',
                    'canonical' => false,
                ]],
            ],
        );
        mkdir($dir . '/packages/unused', 0o755, true);
        file_put_contents(
            $dir . '/packages/unused/composer.json',
            json_encode([
                'name' => 'waaseyaa/unused',
                'config' => ['sort-packages' => true],
                'require' => ['php' => '>=8.5'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        [$exitCode, $stdout] = $this->runScript($dir);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('FAIL [CP007]', $stdout);
        self::assertStringContainsString('waaseyaa/unused path repository has no matching requirement', $stdout);
    }

    // ── CP-NEW: cross-file consistency ────────────────────────────────────────

    #[Test]
    public function cp_new_passes_when_all_constraints_match_current_tag(): void
    {
        $this->requireLiveConstraint();

        // Use the live tag as the internal constraint — CP-NEW must pass.
        $dir = $this->makeFixture(
            pkgRequire: ['waaseyaa/foundation' => self::$liveConstraint],
        );

        [$exitCode, $stdout] = $this->runScript($dir);
        self::assertSame(
            0,
            $exitCode,
            "CP-NEW should pass when all constraints match the current tag. stdout={$stdout}",
        );
    }

    // CP-NEW negative (stale constraint → FAIL [CP-NEW]) is deferred to a
    // follow-up: it requires a fixture git repo initialised with a specific
    // vX.Y.Z tag so the comparison is deterministic without touching the live
    // repo state. See #1384 follow-up issue.

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Skip the current test when no live git tag is available.
     * Avoids false failures in shallow CI clones without fetch-tags: true.
     */
    private function requireLiveConstraint(): void
    {
        if (self::$liveConstraint === null) {
            self::markTestSkipped(
                'No vX.Y.Z git tag found in repo — CP-NEW cannot resolve an expected constraint. '
                . 'Run in a clone with at least one semver tag.',
            );
        }
    }

    /**
     * Build a minimal monorepo fixture tree sufficient for the policy script.
     *
     * Both root and the single package manifest always include sort-packages=true
     * and the php constraint so they don't generate CP001 noise in tests focused
     * on other rules. Callers may override via $rootExtra / $pkgExtra.
     *
     * @param array<string, mixed>  $rootExtra   Merged (replace) into the root composer.json.
     * @param array<string, mixed>  $pkgExtra    Merged (replace) into packages/example/composer.json.
     * @param array<string, string> $rootRequire Additional entries for root require.
     * @param array<string, string> $pkgRequire  Additional entries for pkg require.
     */
    private function makeFixture(
        array $rootExtra = [],
        array $pkgExtra = [],
        array $rootRequire = [],
        array $pkgRequire = [],
        bool $addPathRepositories = true,
    ): string {
        $base = sys_get_temp_dir() . '/waaseyaa_policy_test_' . uniqid('', true);
        mkdir($base, 0o755, true);
        $this->tempDirs[] = $base;

        // array_replace_recursive replaces scalar values (safe for booleans like
        // sort-packages), unlike array_merge_recursive which would nest them.
        $rootManifest = array_replace_recursive(
            [
                'name' => 'waaseyaa/framework',
                'config' => ['sort-packages' => true],
                'require' => array_merge(['php' => '>=8.5'], $rootRequire),
            ],
            $rootExtra,
        );

        file_put_contents(
            $base . '/composer.json',
            json_encode($rootManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        $pkgDir = $base . '/packages/example';
        mkdir($pkgDir, 0o755, true);

        $pkgManifest = array_replace_recursive(
            [
                'name' => 'waaseyaa/example',
                'config' => ['sort-packages' => true],
                'require' => array_merge(['php' => '>=8.5'], $pkgRequire),
            ],
            $pkgExtra,
        );

        if ($addPathRepositories && !array_key_exists('repositories', $pkgExtra)) {
            $pkgManifest['repositories'] = $this->createInternalPackageFixtures($base, $pkgRequire);
        }

        file_put_contents(
            $pkgDir . '/composer.json',
            json_encode($pkgManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return $base;
    }

    /**
     * Build a fixture tree that includes packages/core/composer.json for CP004 tests.
     *
     * @param array<string, string> $coreRequire Entries for packages/core require section.
     */
    private function makeFixtureWithCore(array $coreRequire = []): string
    {
        $base = sys_get_temp_dir() . '/waaseyaa_policy_test_' . uniqid('', true);
        mkdir($base, 0o755, true);
        $this->tempDirs[] = $base;

        // Root manifest.
        file_put_contents(
            $base . '/composer.json',
            json_encode([
                'name' => 'waaseyaa/framework',
                'config' => ['sort-packages' => true],
                'require' => ['php' => '>=8.5'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        // packages/core/composer.json — the only manifest CP004 inspects.
        $coreDir = $base . '/packages/core';
        mkdir($coreDir, 0o755, true);

        $repositories = $this->createInternalPackageFixtures($base, $coreRequire);

        file_put_contents(
            $coreDir . '/composer.json',
            json_encode([
                'name' => 'waaseyaa/core',
                'repositories' => $repositories,
                'config' => ['sort-packages' => true],
                'require' => $coreRequire,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return $base;
    }

    /**
     * @param array<string, string> $requirements
     * @return list<array{type: string, url: string}>
     */
    private function createInternalPackageFixtures(string $base, array $requirements): array
    {
        $repositories = [];
        foreach (array_keys($requirements) as $packageName) {
            if (!str_starts_with($packageName, 'waaseyaa/')) {
                continue;
            }
            $shortName = substr($packageName, strlen('waaseyaa/'));
            $targetDir = $base . '/packages/' . $shortName;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0o755, true);
                file_put_contents(
                    $targetDir . '/composer.json',
                    json_encode([
                        'name' => $packageName,
                        'config' => ['sort-packages' => true],
                        'require' => ['php' => '>=8.5'],
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                );
            }
            $repositories[] = ['type' => 'path', 'url' => '../' . $shortName];
        }

        return $repositories;
    }

    /**
     * Run bin/check-composer-policy against the given fixture root.
     *
     * Merges the full process environment so python3, git, and php remain
     * discoverable, then overrides ROOT_DIR to point at the fixture tree.
     *
     * Returns [exitCode, stdout, stderr].
     *
     * @return array{int, string, string}
     */
    private function runScript(string $fixtureRoot): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge(
            getenv(),
            ['ROOT_DIR' => $fixtureRoot],
        );

        $process = proc_open(
            [PHP_BINARY, self::BIN],
            $descriptors,
            $pipes,
            $fixtureRoot,
            $env,
        );

        self::assertIsResource($process, 'proc_open failed for bin/check-composer-policy');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [$exitCode, $stdout, $stderr];
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $dir . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($dir);
    }
}
