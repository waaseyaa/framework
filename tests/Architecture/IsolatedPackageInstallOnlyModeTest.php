<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the --install-only=<file> / --run-tests=<dir> seam added to
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
 *
 * The hand-off itself was a round-3 regression (round 4 fix): the
 * workflow originally captured the script's whole stdout via command
 * substitution to get the isolation path, but Composer's own stdout is
 * never redirected inside the script, and Composer prints a startup
 * deprecation notice from its bundled react/promise on every invocation
 * (on some PHP/Composer version combinations — see round 5 note below) —
 * that chatter rode along with the path and produced a multi-line value
 * that broke GITHUB_ENV's parser. The fix moved the hand-off to a
 * dedicated file the script writes only at its own successful hand-off
 * point.
 *
 * Round 5: installOnlyHandOffFileIsImmuneToComposerStdoutNoise() originally
 * asserted against the real `composer` binary's authentic startup noise as
 * vacuity proof (round 4). That broke on GitHub's runners: local Composer
 * 2.8.8 on PHP 8.5 emits the react/promise deprecation notice, but CI
 * resolved Composer 2.10.2, which no longer does — the assertion was
 * unintentionally keyed to one Composer version. The property under test
 * (stdout carries noise; the hand-off file still contains exactly the
 * isolation path) does not depend on Composer at all, so the noise is now
 * synthetic and fully self-controlled: the stub emits a deterministic
 * marker line that cannot drift with any Composer or PHP upgrade.
 */
#[CoversNothing]
final class IsolatedPackageInstallOnlyModeTest extends TestCase
{
    /**
     * Deterministic, self-defined stdout noise (round 5 fix): stands in for
     * "whatever a subprocess during the install phase happens to print" —
     * originally the real Composer binary's own startup chatter, which
     * turned out to vary by Composer version (present on 2.8.8, absent on
     * 2.10.2) and broke the vacuity guard in CI. A literal constant this
     * file owns cannot drift with any third-party tool's version.
     */
    private const NOISE_MARKER = 'SYNTHETIC-STDOUT-NOISE-MARKER-2404';

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
        $handoff = $this->handoffPath();
        $result = $this->runScript(['access', '--install-only=' . $handoff], $marker);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertFileExists($handoff, 'install-only must write the hand-off file.');

        $isolationDir = trim((string) file_get_contents($handoff));
        $this->cleanupPaths[] = $isolationDir;

        self::assertMatchesRegularExpression(
            '#^' . preg_quote(sys_get_temp_dir(), '#') . '/waaseyaa-access-isolation\.#',
            $isolationDir,
            'install-only must write the isolation root, matching the same prefix cleanup() guards.',
        );
        self::assertDirectoryExists($isolationDir . '/repository/packages/access/vendor');
        self::assertFileDoesNotExist($marker, 'install-only must not run the isolated suite.');
        self::assertDirectoryExists($isolationDir, 'install-only must hand off the directory, not clean it up.');
    }

    #[Test]
    public function runTestsExecutesOnceAndCleansUpTheHandedOffDirectory(): void
    {
        $marker = $this->markerPath();
        $handoff = $this->handoffPath();
        $installResult = $this->runScript(['access', '--install-only=' . $handoff], $marker);
        self::assertSame(0, $installResult['exit_code'], $installResult['output']);
        $isolationDir = trim((string) file_get_contents($handoff));

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

    #[Test]
    public function anEmptyInstallOnlyFileArgumentIsRejectedWithUsage(): void
    {
        $result = $this->runScript(['access', '--install-only='], $this->markerPath());

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('Usage: bin/test-isolated-package access', $result['output']);
    }

    /**
     * The regression this closes: the workflow used to capture this
     * script's stdout via `isolation_dir="$(bash bin/test-isolated-package
     * access --install-only)"`. Composer's own stdout was never
     * redirected, so any noise it printed rode along ahead of the path.
     *
     * Round 4 proved this against the real `composer` binary's startup
     * chatter; that broke in CI (local Composer 2.8.8 on PHP 8.5 emits a
     * react/promise deprecation notice, CI's Composer 2.10.2 does not) —
     * the vacuity guard was unintentionally keyed to one Composer version.
     * The property under test has nothing to do with Composer specifically:
     * it is "arbitrary stdout noise during the install phase must not reach
     * the hand-off file". Round 5 makes the noise deterministic and
     * self-generated instead — the stub emits its own guaranteed marker
     * line, unrelated to any real Composer output — so this assertion
     * cannot break on a Composer or PHP upgrade: nothing about it depends
     * on what any third-party tool prints or which version is installed,
     * only on a string this test file itself defines and controls.
     */
    #[Test]
    public function installOnlyHandOffFileIsImmuneToStdoutNoise(): void
    {
        $marker = $this->markerPath();
        $handoff = $this->handoffPath();
        $result = $this->runScript(
            ['access', '--install-only=' . $handoff],
            $marker,
            emitSyntheticStdoutNoise: true,
        );

        self::assertSame(0, $result['exit_code'], $result['output']);

        // The process's own stdout genuinely carried noise — this is what
        // makes the test meaningful rather than a silent stub that could
        // never have caught the regression in the first place. The marker
        // is synthetic and defined entirely by this test (self::NOISE_MARKER
        // below), so this can never depend on a third party's version or
        // behaviour.
        self::assertStringContainsString(
            self::NOISE_MARKER,
            $result['output'],
            'The stub must actually have printed its synthetic noise for this test to prove anything.',
        );

        self::assertFileExists($handoff);
        $handoffContents = (string) file_get_contents($handoff);
        $lines = array_values(array_filter(explode("\n", $handoffContents), static fn(string $line): bool => $line !== ''));

        self::assertCount(
            1,
            $lines,
            "Hand-off file must contain exactly one line (the isolation path) — got:\n" . $handoffContents,
        );

        $isolationDir = $lines[0];
        $this->cleanupPaths[] = $isolationDir;
        self::assertMatchesRegularExpression(
            '#^' . preg_quote(sys_get_temp_dir(), '#') . '/waaseyaa-access-isolation\.#',
            $isolationDir,
            'The single line in the hand-off file must be exactly the isolation path, with no stdout noise mixed in.',
        );
        self::assertStringNotContainsString(self::NOISE_MARKER, $handoffContents);
    }

    private function markerPath(): string
    {
        $marker = sys_get_temp_dir() . '/waaseyaa_test_isolated_package_marker_' . uniqid('', true);
        $this->cleanupPaths[] = $marker;

        return $marker;
    }

    private function handoffPath(): string
    {
        $handoff = sys_get_temp_dir() . '/waaseyaa_test_isolated_package_handoff_' . uniqid('', true);
        $this->cleanupPaths[] = $handoff;

        return $handoff;
    }

    /** @param list<string> $args */
    /** @return array{exit_code: int, output: string} */
    private function runScript(array $args, string $markerFile, bool $emitSyntheticStdoutNoise = false): array
    {
        $stubBin = sys_get_temp_dir() . '/waaseyaa_composer_stub_' . uniqid('', true);
        mkdir($stubBin, 0o777, true);
        $this->cleanupPaths[] = $stubBin;

        // Fabricates the minimal vendor/ shape bin/test-isolated-package
        // expects (vendor/bin/phpunit, vendor/autoload.php) without a real
        // network-touching `composer install`. The stub phpunit touches
        // $markerFile instead of running real tests, so tests here can
        // assert "did the suite run" without depending on real PHPUnit.
        //
        // When $emitSyntheticStdoutNoise is true, the stub prints a blank
        // line plus self::NOISE_MARKER before fabricating vendor/,
        // deliberately unredirected so it flows through to this process's
        // stdout exactly like the real `composer install` step's own
        // output does. This used to shell out to the real `composer`
        // binary for authentic startup chatter, but that chatter turned
        // out to be Composer-version-dependent (present on 2.8.8, absent
        // on 2.10.2) and broke in CI — see the round-5 note on
        // installOnlyHandOffFileIsImmuneToStdoutNoise(). A synthetic,
        // self-defined marker exercises the identical property (stdout
        // noise during install must never reach the hand-off file)
        // without depending on any third party's version or behaviour, so
        // invoking the real binary added no assertion value once the
        // marker was self-controlled — dropped rather than kept for
        // flavour alone.
        $noisyPreamble = $emitSyntheticStdoutNoise
            ? 'echo ""; echo ' . escapeshellarg(self::NOISE_MARKER)
            : ': no-op';
        file_put_contents(
            $stubBin . '/composer',
            <<<BASH
                #!/usr/bin/env bash
                set -euo pipefail
                if [ "\${1:-}" = "install" ]; then
                  {$noisyPreamble}
                  mkdir -p vendor/bin
                  cat > vendor/bin/phpunit <<'PHP'
                <?php
                touch(getenv('ISOLATED_PACKAGE_TEST_MARKER'));
                fwrite(STDOUT, "stub phpunit ran\\n");
                PHP
                  : > vendor/autoload.php
                  exit 0
                fi
                echo "unsupported composer stub invocation: \$*" >&2
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
