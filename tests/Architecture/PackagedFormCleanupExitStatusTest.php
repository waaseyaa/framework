<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Locks #2870's teardown acceptance criterion: an EXIT trap's best-effort
 * `rm -rf -- "$work"` must never change a check-* script's exit status, even
 * though the removal itself can genuinely fail (a lingering process, a
 * read-only subdirectory, a filesystem race against something the proof
 * itself just created).
 *
 * The defect: `set -euo pipefail` plus
 *
 *     cleanup() {
 *         rm -rf -- "$work"
 *     }
 *     trap cleanup EXIT
 *
 * means a failing `rm` inside the trap is itself an error under `set -e`, and
 * that error's exit status becomes the SCRIPT's exit status -- overwriting
 * whatever the proof under test actually decided, including a clean success.
 * negative_control_the_pre_fix_shape_corrupts_a_successful_exit() runs that
 * exact shape verbatim and proves it really does happen, so this guard cannot
 * later be deleted as redundant.
 *
 * Every script under tests/PackagedForm/ or tests/ReferenceConsumer/ that
 * installs a `trap cleanup EXIT` must therefore be on the roster below. A
 * script with the same trap shape that is not on the roster fails this test
 * -- the defect is in the pattern, not in any one file (#2870).
 */
#[CoversNothing]
final class PackagedFormCleanupExitStatusTest extends TestCase
{
    /**
     * Every tests/PackagedForm/check-* script that installs `trap cleanup
     * EXIT`. check-consumer-key-hygiene is deliberately absent: it has no
     * trap at all.
     *
     * @var list<string>
     */
    private const SCRIPTS_WITH_EXIT_TRAP = [
        'check-bimaaji-skill-resources',
        'check-cli-ai-commands-optional',
        'check-cli-health-report',
        'check-cli-oidc-commands-optional',
        'check-cli-sync-rules',
        'check-custom-field-admission',
        'check-fresh-install-boot',
        'check-s1-configuration-archives',
        'check-s1-configuration-core-only',
        'check-s1-schema-authority-artifact',
        'check-s1-sqlite-artifact',
        'check-split-artifact-acceptance',
        'check-studio-alpha-acceptance',
        'check-support-contract-packaged',
        'check-verified-config-import',
    ];

    /**
     * Every tests/ReferenceConsumer/check-* script that installs `trap
     * cleanup EXIT`. This is the required `site-reference-consumer` CI job
     * (.github/workflows/ci.yml) and, because its cleanup fires after a
     * `git init` against the work directory, the most exposed instance of
     * the #2870 shape in the repository.
     *
     * @var list<string>
     */
    private const REFERENCE_CONSUMER_SCRIPTS_WITH_EXIT_TRAP = [
        'check-reference-consumer',
    ];

    #[Test]
    public function every_check_script_with_an_exit_trap_is_on_the_roster(): void
    {
        self::assertSame(
            self::SCRIPTS_WITH_EXIT_TRAP,
            self::scanForExitTrapScripts('tests/PackagedForm'),
            "A tests/PackagedForm/check-* script installed (or removed) a `trap cleanup EXIT`.\n"
            . "Add it to self::SCRIPTS_WITH_EXIT_TRAP above and give its cleanup() the exit-status-neutral\n"
            . 'shape this test enforces (#2870) -- the defect is in the pattern, not in any one file.',
        );
        self::assertSame(
            self::REFERENCE_CONSUMER_SCRIPTS_WITH_EXIT_TRAP,
            self::scanForExitTrapScripts('tests/ReferenceConsumer'),
            "A tests/ReferenceConsumer/check-* script installed (or removed) a `trap cleanup EXIT`.\n"
            . "Add it to self::REFERENCE_CONSUMER_SCRIPTS_WITH_EXIT_TRAP above and give its cleanup() the\n"
            . 'exit-status-neutral shape this test enforces (#2870).',
        );
    }

    #[Test]
    public function every_rostered_script_neutralizes_its_cleanup_exit_status(): void
    {
        $root = self::repositoryRoot();
        $rosters = [
            'tests/PackagedForm' => self::SCRIPTS_WITH_EXIT_TRAP,
            'tests/ReferenceConsumer' => self::REFERENCE_CONSUMER_SCRIPTS_WITH_EXIT_TRAP,
        ];

        foreach ($rosters as $relativeDir => $scripts) {
            foreach ($scripts as $name) {
                $path = $root . '/' . $relativeDir . '/' . $name;
                $source = (string) file_get_contents($path);

                $body = self::cleanupBody($source);
                self::assertNotNull($body, "{$name}: could not locate a cleanup() function body.");

                // The removal must be wired with `||` so a failing `rm` cannot
                // trip `set -e` and overwrite the script's real exit status.
                // The work-directory variable name varies by script (`$work`,
                // `$work_root`, ...), so match any bare `"$identifier"`.
                self::assertMatchesRegularExpression(
                    '/rm\s+-rf\s+--\s+"\$\w+"\s*\|\|/',
                    $body,
                    "{$name}: cleanup() must guard its `rm -rf -- \"\$...\"` with `||` so a failed removal "
                    . 'cannot change the script exit status (#2870).',
                );

                // A leak must still be visible -- on stderr, not swallowed.
                self::assertMatchesRegularExpression(
                    '/\|\|[^\n]*>&2/',
                    $body,
                    "{$name}: cleanup() neutralizes the exit status but must still report a leaked directory "
                    . 'on stderr rather than silently discarding it (#2870).',
                );

                // The invariant must be stated in prose next to the code, so a
                // future editor cannot "simplify" the guard back into the bug.
                // Allows the comment to wrap across lines (still `#`-prefixed).
                self::assertMatchesRegularExpression(
                    '/#[^\n]*exit[\s#]*status/i',
                    $body,
                    "{$name}: cleanup() must carry a comment stating that teardown must never change the "
                    . 'script exit status (#2870).',
                );
            }
        }
    }

    #[Test]
    public function negative_control_the_pre_fix_shape_corrupts_a_successful_exit(): void
    {
        // Without this, the two assertions above cannot be told apart from
        // theatre: run the EXACT pre-fix shape from #2870's report, against a
        // work directory whose removal genuinely fails, and watch a script
        // that did nothing but succeed exit non-zero anyway.
        $work = self::makeUnremovableWorkDir();

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            work="$1"
            cleanup() {
                rm -rf -- "$work"
            }
            trap cleanup EXIT
            echo 'proof passed'
            SH;

        $process = self::runInlineScript($script, [$work]);

        self::assertStringContainsString(
            'proof passed',
            $process->getOutput(),
            'The proof itself must actually have run and succeeded before cleanup corrupted anything.',
        );
        self::assertNotSame(
            0,
            $process->getExitCode(),
            'This negative control demonstrates the #2870 corruption: the pre-fix cleanup shape, run '
            . "against a work directory whose removal genuinely fails, must overwrite a successful exit.\n"
            . "If this now passes with exit 0, the OS/filesystem no longer reproduces the premise this\n"
            . "whole test class rests on -- re-derive makeUnremovableWorkDir(), do not delete this test.\n"
            . $process->getErrorOutput(),
        );
        self::assertStringContainsString('Permission denied', $process->getErrorOutput());
    }

    #[Test]
    public function fixed_shape_reports_a_leak_without_corrupting_a_successful_exit(): void
    {
        $work = self::makeUnremovableWorkDir();

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            work="$1"
            cleanup() {
                # Cleanup is best-effort and must never change this script's
                # exit status; report a leak on stderr instead (#2870).
                rm -rf -- "$work" || echo "warning: failed to remove $work" >&2
            }
            trap cleanup EXIT
            echo 'proof passed'
            SH;

        $process = self::runInlineScript($script, [$work]);

        self::assertSame(
            0,
            $process->getExitCode(),
            "Fixed cleanup shape must not corrupt a successful exit.\n" . $process->getErrorOutput(),
        );
        self::assertStringContainsString('proof passed', $process->getOutput());
        self::assertStringContainsString('warning: failed to remove', $process->getErrorOutput());
    }

    #[Test]
    public function fixed_shape_still_propagates_an_explicit_failure(): void
    {
        // The critical corollary: neutralizing cleanup must not neutralize
        // a genuine proof failure that happens to run before teardown.
        $work = self::makeUnremovableWorkDir();

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            work="$1"
            cleanup() {
                # Cleanup is best-effort and must never change this script's
                # exit status; report a leak on stderr instead (#2870).
                rm -rf -- "$work" || echo "warning: failed to remove $work" >&2
            }
            trap cleanup EXIT
            echo 'about to fail explicitly' >&2
            exit 7
            SH;

        $process = self::runInlineScript($script, [$work]);

        self::assertSame(
            7,
            $process->getExitCode(),
            "An explicit `exit 7` before cleanup must still propagate through the neutralized trap.\n"
            . $process->getErrorOutput(),
        );
    }

    /**
     * Builds a temp directory that `rm -rf` cannot fully remove: a
     * subdirectory with its own permissions stripped, so `rm` can neither
     * unlink the file inside it nor remove the (non-empty) directory itself.
     * Skipped rather than run under root, where permission checks are
     * bypassed and the premise would not hold.
     */
    private static function makeUnremovableWorkDir(): string
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('This proof requires a non-root user; permission checks are bypassed as root.');
        }

        $work = sys_get_temp_dir() . '/waaseyaa_cleanup_exit_status_' . bin2hex(random_bytes(8));
        $locked = $work . '/locked';
        mkdir($locked, 0o755, true);
        file_put_contents($locked . '/f', 'x');
        chmod($locked, 0o000);

        return $work;
    }

    /** @param list<string> $arguments */
    private static function runInlineScript(string $script, array $arguments): Process
    {
        $scriptFile = tempnam(sys_get_temp_dir(), 'waaseyaa_cleanup_exit_status_script_');
        file_put_contents($scriptFile, $script . "\n");
        chmod($scriptFile, 0o755);

        try {
            $process = new Process(array_merge(['bash', $scriptFile], $arguments));
            $process->run();

            return $process;
        } finally {
            @unlink($scriptFile);
            // Restore permissions so the harness's own teardown can clean up
            // regardless of which cleanup shape the child script exercised.
            foreach ($arguments as $argument) {
                if (is_dir($argument . '/locked')) {
                    @chmod($argument . '/locked', 0o755);
                }
            }
            $fs = new Filesystem();
            foreach ($arguments as $argument) {
                if (is_dir($argument)) {
                    $fs->remove($argument);
                }
            }
        }
    }

    private static function cleanupBody(string $source): ?string
    {
        if (preg_match('/cleanup\s*\(\)\s*\{(.*?)\n\}/s', $source, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** @return list<string> */
    private static function scanForExitTrapScripts(string $relativeDir): array
    {
        $dir = self::repositoryRoot() . '/' . $relativeDir;
        $found = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if (!str_starts_with($entry, 'check-')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (str_contains($source, 'trap cleanup EXIT')) {
                $found[] = $entry;
            }
        }

        sort($found);

        return $found;
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
