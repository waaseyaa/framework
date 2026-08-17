<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the --install-only / --run-tests=<dir> seam added to
 * bin/test-isolated-package (#2404 revision, round 3, item 3): CI retries
 * only the network-touching install phase with bounded backoff, and runs
 * the isolated PHPUnit suite exactly once, unretried, so a deterministic
 * or flaky/order-dependent test failure cannot be masked by a network
 * retry loop.
 *
 * Runs the real script against the real packages/access tree (no network
 * needed for the tar-copy step) but shadows `composer` on PATH with a
 * stub that fabricates a minimal vendor/ (a stub vendor/bin/phpunit that
 * touches a marker file instead of running real PHPUnit) — this keeps the
 * test hermetic and fast while still exercising the script's real control
 * flow, argument parsing, hand-off, and cleanup ownership.
 */
#[CoversNothing]
final class IsolatedPackageInstallOnlyModeTest extends TestCase
{
    private string $repoRoot;

    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            $this->removeTree($path);
        }
        $this->cleanupPaths = [];
    }

    #[Test]
    public function installOnlyHandsOffWithoutRunningTests(): void
    {
        $marker = $this->markerPath();
        $result = $this->runScript(['access', '--install-only'], $marker);

        self::assertSame(0, $result['exit_code'], $result['output']);

        $isolationDir = trim($result['output']);
        $this->cleanupPaths[] = $isolationDir;

        self::assertMatchesRegularExpression(
            '#^' . preg_quote(sys_get_temp_dir(), '#') . '/waaseyaa-access-isolation\.#',
            $isolationDir,
            'install-only must print the isolation root, matching the same prefix cleanup() guards.',
        );
        self::assertDirectoryExists($isolationDir . '/repository/packages/access/vendor');
        self::assertFileDoesNotExist($marker, 'install-only must not run the isolated suite.');
        self::assertDirectoryExists($isolationDir, 'install-only must hand off the directory, not clean it up.');
    }

    #[Test]
    public function runTestsExecutesOnceAndCleansUpTheHandedOffDirectory(): void
    {
        $marker = $this->markerPath();
        $installResult = $this->runScript(['access', '--install-only'], $marker);
        self::assertSame(0, $installResult['exit_code'], $installResult['output']);
        $isolationDir = trim($installResult['output']);

        $runResult = $this->runScript(['access', '--run-tests=' . $isolationDir], $marker);

        self::assertSame(0, $runResult['exit_code'], $runResult['output']);
        self::assertFileExists($marker, 'run-tests must execute the isolated suite exactly once.');
        self::assertDirectoryDoesNotExist($isolationDir, 'run-tests owns cleanup of the handed-off directory.');
    }

    #[Test]
    public function defaultModeStillInstallsAndRunsInOneCall(): void
    {
        $marker = $this->markerPath();
        $result = $this->runScript(['access'], $marker);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertFileExists($marker, 'Unflagged invocation must keep running install then tests in one call.');
        // Unflagged mode never hands a path back to the caller — nothing
        // printed to stdout beyond whatever the stub phpunit itself wrote.
        self::assertStringNotContainsString('waaseyaa-access-isolation', $result['output']);
    }

    #[Test]
    public function runTestsRefusesAPathOutsideTheIsolationPrefix(): void
    {
        $foreign = sys_get_temp_dir() . '/not-an-isolation-dir-' . uniqid('', true);
        mkdir($foreign);
        $this->cleanupPaths[] = $foreign;

        $result = $this->runScript(['access', '--run-tests=' . $foreign], $this->markerPath());

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('Refusing to use unexpected isolation path', $result['output']);
        // Refused, so the foreign directory must survive untouched.
        self::assertDirectoryExists($foreign);
    }

    #[Test]
    public function anUnknownFlagIsRejectedWithUsage(): void
    {
        $result = $this->runScript(['access', '--bogus'], $this->markerPath());

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('Usage: bin/test-isolated-package access', $result['output']);
    }

    private function markerPath(): string
    {
        $marker = sys_get_temp_dir() . '/waaseyaa_test_isolated_package_marker_' . uniqid('', true);
        $this->cleanupPaths[] = $marker;

        return $marker;
    }

    /** @param list<string> $args */
    /** @return array{exit_code: int, output: string} */
    private function runScript(array $args, string $markerFile): array
    {
        $stubBin = sys_get_temp_dir() . '/waaseyaa_composer_stub_' . uniqid('', true);
        mkdir($stubBin, 0o777, true);
        $this->cleanupPaths[] = $stubBin;

        // Fabricates the minimal vendor/ shape bin/test-isolated-package
        // expects (vendor/bin/phpunit, vendor/autoload.php) without a real
        // network-touching `composer install`. The stub phpunit touches
        // $markerFile instead of running real tests, so tests here can
        // assert "did the suite run" without depending on real PHPUnit.
        file_put_contents(
            $stubBin . '/composer',
            <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                if [ "${1:-}" = "install" ]; then
                  mkdir -p vendor/bin
                  cat > vendor/bin/phpunit <<'PHP'
                <?php
                touch(getenv('ISOLATED_PACKAGE_TEST_MARKER'));
                fwrite(STDOUT, "stub phpunit ran\n");
                PHP
                  : > vendor/autoload.php
                  exit 0
                fi
                echo "unsupported composer stub invocation: $*" >&2
                exit 1
                BASH,
        );
        chmod($stubBin . '/composer', 0o755);

        $command = array_merge([$this->repoRoot . '/bin/test-isolated-package'], $args);
        // Inherit the full parent environment (not a curated subset): the
        // script's own path-safety checks key off ${TMPDIR:-/tmp}, which
        // must match what PHP's sys_get_temp_dir() sees in this test for
        // the isolation-prefix assertions below to be meaningful.
        $env = getenv();
        $env['PATH'] = $stubBin . ':' . (getenv('PATH') ?: '/usr/bin:/bin');
        $env['ISOLATED_PACKAGE_TEST_MARKER'] = $markerFile;

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repoRoot, $env);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'output' => $stdout . $stderr];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isLink() || $entry->isFile() ? unlink($entry->getPathname()) : rmdir($entry->getPathname());
        }
        rmdir($path);
    }
}
