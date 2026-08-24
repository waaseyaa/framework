<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\ReleaseTooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\Tests\Support\ReplacesProcessEnvironment;

/**
 * Integration tests for the CP-NEW gate in bin/check-composer-policy.
 *
 * CP-NEW verifies that every waaseyaa/* constraint in packages/<name>/composer.json
 * matches ^<checked-out VERSION>, with a git-tag fallback for repositories
 * without that tracked file. These tests run the script in controlled temp
 * directories so they are hermetic and do not depend on real package manifests.
 *
 * Strategy: each test sets up a minimal fake repo root with a packages/foo/
 * subdirectory, optionally a git repo with a tag, then shells out to
 * bin/check-composer-policy with that root injected via ROOT_DIR.
 */
#[CoversNothing]
final class CpNewCheckTest extends TestCase
{
    use ReplacesProcessEnvironment;

    private string $tempDir = '';

    /** Path to the real check-composer-policy script. */
    private string $scriptPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir   = sys_get_temp_dir() . '/waaseyaa_cpnew_test_' . uniqid('', true);
        $this->scriptPath = dirname(__DIR__, 3) . '/bin/check-composer-policy';
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            $this->rmdirRecursive($this->tempDir);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create a minimal repo root in $this->tempDir:
     * - a root composer.json (passes CP001, CP006)
     * - packages/<name>/composer.json with the given require map
     * - optionally a git repo with a tag so CP-NEW can resolve a version
     *
     * @param array<string,string> $packageRequire   e.g. ['waaseyaa/bar' => '^0.1.0-alpha.99']
     * @param array<string,string> $packageRequireDev
     */
    private function scaffoldRepo(
        string $packageName,
        array $packageRequire = [],
        array $packageRequireDev = [],
        string|null $gitTag = null,
    ): void {
        // Root manifest — minimal valid shape for existing CP rules.
        $rootManifest = [
            'name'    => 'waaseyaa/framework',
            'require' => new \stdClass(), // empty object
            'config'  => ['sort-packages' => true],
        ];
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode($rootManifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        // Package manifest.
        $pkgDir = $this->tempDir . '/packages/' . $packageName;
        mkdir($pkgDir, 0o755, true);

        $pkgManifest = [
            'name'   => 'waaseyaa/' . $packageName,
            'config' => ['sort-packages' => true],
        ];
        if ($packageRequire !== []) {
            $pkgManifest['require'] = $packageRequire;
        }
        if ($packageRequireDev !== []) {
            $pkgManifest['require-dev'] = $packageRequireDev;
        }
        $internalDependencies = array_unique(array_merge(
            array_keys(array_filter($packageRequire, static fn(string $constraint, string $name): bool => str_starts_with($name, 'waaseyaa/'), ARRAY_FILTER_USE_BOTH)),
            array_keys(array_filter($packageRequireDev, static fn(string $constraint, string $name): bool => str_starts_with($name, 'waaseyaa/'), ARRAY_FILTER_USE_BOTH)),
        ));
        if ($internalDependencies !== []) {
            $pkgManifest['repositories'] = array_map(
                static fn(string $name): array => [
                    'type' => 'path',
                    'url' => '../' . substr($name, strlen('waaseyaa/')),
                ],
                $internalDependencies,
            );
        }

        file_put_contents(
            $pkgDir . '/composer.json',
            json_encode($pkgManifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        foreach ($internalDependencies as $dependency) {
            $dependencyDir = $this->tempDir . '/packages/' . substr($dependency, strlen('waaseyaa/'));
            mkdir($dependencyDir, 0o755, true);
            file_put_contents(
                $dependencyDir . '/composer.json',
                json_encode([
                    'name' => $dependency,
                    'config' => ['sort-packages' => true],
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
        }

        // Init git repo and optionally add a tag so CP-NEW can resolve.
        // Set local user.email/user.name so `git commit` works in CI containers
        // that have no global git identity configured.
        exec('git -C ' . escapeshellarg($this->tempDir) . ' init -q 2>&1');
        exec('git -C ' . escapeshellarg($this->tempDir) . ' config user.email "test@example.com" 2>&1');
        exec('git -C ' . escapeshellarg($this->tempDir) . ' config user.name "Test" 2>&1');
        exec('git -C ' . escapeshellarg($this->tempDir) . ' commit --allow-empty -m "init" -q 2>&1');

        if ($gitTag !== null) {
            exec('git -C ' . escapeshellarg($this->tempDir) . ' tag ' . escapeshellarg($gitTag) . ' 2>&1');
        }
    }

    /**
     * Run bin/check-composer-policy with ROOT_DIR pointing at $this->tempDir.
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runGate(): array
    {
        // proc_open drained stdout to EOF before touching stderr, so a gate that
        // filled the ~64KB stderr buffer wedged both sides (#2491).
        // proc_open received an EXPLICIT env array, which REPLACES the child
        // environment; Symfony Process merges instead, so replacingEnv() pins
        // every inherited name the caller did not set. stdin was opened but
        // never written, so $input null is equivalent. timeout null preserves
        // the previous absence of any time bound.
        $env = array_merge(getenv(), ['ROOT_DIR' => $this->tempDir]);

        $process = new Process(
            [PHP_BINARY, $this->scriptPath],
            $this->tempDir,
            self::replacingEnv($env),
            null,
            null,
        );
        $exit_code = $process->run();

        return [
            'exit_code' => $exit_code,
            'stdout'    => $process->getOutput(),
            'stderr'    => $process->getErrorOutput(),
        ];
    }

    private function rmdirRecursive(string $dir): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_dir($full)) {
                $this->rmdirRecursive($full);
                continue;
            }

            chmod($full, 0o666);
            unlink($full);
        }
        rmdir($dir);
    }

    // ------------------------------------------------------------------
    // Test cases
    // ------------------------------------------------------------------

    #[Test]
    public function tampered_constraint_in_a_package_produces_violation(): void
    {
        // Package has alpha.99 but current tag is v0.1.0-alpha.176.
        $this->scaffoldRepo(
            packageName: 'foo',
            packageRequire: ['waaseyaa/bar' => '^0.1.0-alpha.99'],
            gitTag: 'v0.1.0-alpha.176',
        );

        $result = $this->runGate();

        self::assertNotEquals(
            0,
            $result['exit_code'],
            'CP-NEW should exit non-zero when a constraint does not match the current tag. '
            . 'stdout: ' . $result['stdout'] . ' stderr: ' . $result['stderr'],
        );

        $combined = $result['stdout'] . $result['stderr'];
        self::assertStringContainsString(
            'CP-NEW',
            $combined,
            'Output should reference the CP-NEW rule identifier.',
        );
        self::assertStringContainsString(
            '^0.1.0-alpha.99',
            $combined,
            'Output should show the actual (wrong) constraint.',
        );
        self::assertStringContainsString(
            '^0.1.0-alpha.176',
            $combined,
            'Output should show the expected constraint derived from the git tag.',
        );
        self::assertStringContainsString(
            'packages/foo/composer.json',
            $combined,
            'Output should include the file path that contains the violation.',
        );
    }

    #[Test]
    public function matched_constraints_produce_no_violation(): void
    {
        // Package constraint already matches the current tag.
        $this->scaffoldRepo(
            packageName: 'bar',
            packageRequire: ['waaseyaa/baz' => '^0.1.0-alpha.176'],
            gitTag: 'v0.1.0-alpha.176',
        );

        $result = $this->runGate();

        self::assertEquals(
            0,
            $result['exit_code'],
            'CP-NEW should exit 0 when all constraints match the current tag. '
            . 'stdout: ' . $result['stdout'] . ' stderr: ' . $result['stderr'],
        );

        self::assertStringNotContainsString(
            'CP-NEW',
            $result['stdout'],
            'No CP-NEW violations should be reported when constraints match.',
        );
    }

    #[Test]
    public function tracked_version_is_authoritative_when_local_tags_are_stale(): void
    {
        $this->scaffoldRepo(
            packageName: 'release-current',
            packageRequire: ['waaseyaa/dependency' => '^0.1.0-alpha.177'],
            gitTag: 'v0.1.0-alpha.176',
        );
        file_put_contents($this->tempDir . '/VERSION', "0.1.0-alpha.177\n");

        $result = $this->runGate();

        self::assertSame(
            0,
            $result['exit_code'],
            'The checked-out VERSION file must keep the gate deterministic when local tag refs are stale. '
            . 'stdout: ' . $result['stdout'] . ' stderr: ' . $result['stderr'],
        );
        self::assertStringNotContainsString('FAIL [CP-NEW]', $result['stdout'] . $result['stderr']);
    }

    #[Test]
    public function malformed_tracked_version_fails_once_with_an_actionable_diagnostic(): void
    {
        $this->scaffoldRepo(
            packageName: 'invalid-release',
            packageRequire: ['waaseyaa/dependency' => '^0.1.0-alpha.176'],
            gitTag: 'v0.1.0-alpha.176',
        );
        file_put_contents($this->tempDir . '/VERSION', "not a version\n");

        $result = $this->runGate();
        $combined = $result['stdout'] . $result['stderr'];

        self::assertNotSame(0, $result['exit_code']);
        self::assertSame(1, substr_count($combined, 'FAIL [CP-NEW]'));
        self::assertStringContainsString('VERSION', $combined);
        self::assertStringContainsString('valid tracked semantic version', $combined);
    }

    #[Test]
    public function no_tags_present_emits_warning_not_error(): void
    {
        // A git repo exists but has no tags — simulates a shallow CI clone
        // without fetch-tags: true, or a brand-new repo before first release.
        $this->scaffoldRepo(
            packageName: 'qux',
            packageRequire: ['waaseyaa/quux' => '^0.1.0-alpha.99'],
            gitTag: null, // intentionally no tag
        );

        $result = $this->runGate();

        self::assertEquals(
            0,
            $result['exit_code'],
            'CP-NEW must exit 0 (not fail hard) when no git tags are found. '
            . 'stdout: ' . $result['stdout'] . ' stderr: ' . $result['stderr'],
        );

        $combined = $result['stdout'] . $result['stderr'];
        self::assertStringContainsString(
            'CP-NEW',
            $combined,
            'A CP-NEW warning must be emitted to explain the skip.',
        );
        self::assertStringNotContainsString(
            'FAIL [CP-NEW]',
            $combined,
            'No FAIL line should appear — only a warning.',
        );
    }

    #[Test]
    public function packages_without_internal_deps_are_skipped(): void
    {
        // Package has only third-party deps — no waaseyaa/* at all.
        $this->scaffoldRepo(
            packageName: 'standalone',
            packageRequire: ['symfony/console' => '^7.0'],
            gitTag: 'v0.1.0-alpha.176',
        );

        $result = $this->runGate();

        self::assertEquals(
            0,
            $result['exit_code'],
            'CP-NEW should produce no violation for a package with no waaseyaa/* deps. '
            . 'stdout: ' . $result['stdout'] . ' stderr: ' . $result['stderr'],
        );

        self::assertStringNotContainsString(
            'CP-NEW',
            $result['stdout'],
            'No CP-NEW output expected when there are no internal dependencies.',
        );
    }
}
