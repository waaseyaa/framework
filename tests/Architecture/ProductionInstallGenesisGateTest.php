<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * #3064. Studio #36 proved that APP_ENV=production install:init still required
 * an active CFG-02 generation after db:init --no-sync-schema. The property is
 * not covered by check-fresh-install-boot, which uses APP_ENV=local.
 *
 * Independent review of 06d45e45 also required every Composer/CLI/probe child
 * to carry an explicit bounded deadline with TERM→KILL escalation and EXIT
 * reaping before tree removal (docs/local-testing-policy.md).
 *
 * Re-review of f0794db required descendant custody: after an early-exiting
 * parent, surviving process-group members must still be reaped, and PGID
 * adoption must not race through unverified `ps`/`setsid` startup.
 *
 * Re-review of b0c2e6d rejected live post-spawn /proc PGID adoption: a fast
 * /bin/true leader exits before observation (status 126), and delaying the
 * observer lets an early parent exit, clears custody, and leaks its sleep
 * descendant. Custody must be recorded from the monitor-mode leader PID
 * immediately (PGID == PID), refusing only caller-group equivalence.
 */
#[CoversNothing]
final class ProductionInstallGenesisGateTest extends TestCase
{
    private const string HARNESS = 'tests/PackagedForm/check-production-install-genesis';

    private const string PROBE = 'tests/ProductionInstallGenesis/probe.php';

    private const string BOUNDED_HELPER = 'tests/PackagedForm/lib/run-bounded.sh';

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_proof_and_probe_exist_and_pin_the_exact_production_sequence(): void
    {
        $harness = $this->repoRoot . '/' . self::HARNESS;
        self::assertFileExists($harness);
        self::assertTrue(is_executable($harness), self::HARNESS . ' must be executable.');
        self::assertFileExists($this->repoRoot . '/' . self::PROBE);
        self::assertFileExists($this->repoRoot . '/' . self::BOUNDED_HELPER);

        $harnessSource = (string) file_get_contents($harness);
        self::assertStringContainsString('APP_ENV=production', $harnessSource);
        self::assertStringContainsString('db:init --no-sync-schema', $harnessSource);
        self::assertStringContainsString('waaseyaa install:init', $harnessSource);
        self::assertStringContainsString('Activated generation', $harnessSource);
        self::assertStringContainsString('Configuration already initialized', $harnessSource);
        self::assertStringContainsString('waaseyaa_config_activation_v2 WHERE is_genesis = 1', $harnessSource);
        self::assertStringContainsString('probe.php refuse', $harnessSource);
        self::assertStringContainsString('probe.php boot', $harnessSource);
        self::assertStringContainsString('field-access:preflight --write-artifact', $harnessSource);
        self::assertStringNotContainsString("printf 'APP_ENV=local", $harnessSource);
        self::assertStringNotContainsString('APP_ENV=local\\n', $harnessSource);

        // Exact-head archived consumer semantics (accepted by independent review).
        self::assertStringContainsString('rev-parse HEAD', $harnessSource);
        self::assertStringContainsString('archive --format=tar "$candidate"', $harnessSource);

        $probe = (string) file_get_contents($this->repoRoot . '/' . self::PROBE);
        self::assertStringContainsString('HttpKernel', $probe);
        self::assertStringContainsString('requireActiveGenerationId', $probe);
        self::assertStringContainsString('production-install ordinary boot refused before genesis', $probe);
        self::assertStringContainsString('production-install ordinary boot OK', $probe);
        self::assertStringContainsString('production-install active generation OK', $probe);
    }

    #[Test]
    public function the_harness_bounds_every_composer_cli_and_probe_child_with_term_then_kill(): void
    {
        $harnessSource = (string) file_get_contents($this->repoRoot . '/' . self::HARNESS);
        $helperSource = (string) file_get_contents($this->repoRoot . '/' . self::BOUNDED_HELPER);

        self::assertStringContainsString('source "$root/tests/PackagedForm/lib/run-bounded.sh"', $harnessSource);
        self::assertStringContainsString('reap_bounded_owned_child', $harnessSource);
        self::assertMatchesRegularExpression(
            '/cleanup\(\)\s*\{[^}]*reap_bounded_owned_child[^}]*rm\s+-rf\s+--\s+"\$work"/s',
            $harnessSource,
            'EXIT cleanup must reap the owned child before removing the work tree.',
        );

        foreach ([
            'run_bounded composer-install',
            'run_bounded db-init',
            'run_bounded refuse',
            'run_bounded install-init',
            'run_bounded install-retry',
            'run_bounded field-access-preflight',
            'run_bounded boot',
            'run_bounded genesis-count',
            'run_bounded genesis-count-retry',
            'run_bounded rewrite-composer',
        ] as $invocation) {
            self::assertStringContainsString($invocation, $harnessSource);
        }

        self::assertStringContainsString('timeout --signal=TERM --kill-after=5s', $helperSource);
        self::assertStringContainsString('set -m', $helperSource);
        self::assertStringContainsString('BOUNDED_OWNED_PGID="$BOUNDED_OWNED_PID"', $helperSource);
        self::assertStringContainsString('refused caller-group equivalence', $helperSource);
        self::assertStringContainsString('Bounded deadline exceeded for', $helperSource);
        self::assertStringContainsString('kill -TERM -- "-$pgid"', $helperSource);
        self::assertStringContainsString('kill -KILL -- "-$pgid"', $helperSource);
        self::assertStringContainsString('surviving descendants', $helperSource);
        self::assertStringContainsString('DEADLINE_COMPOSER=', $harnessSource);
        self::assertStringContainsString('DEADLINE_INSTALL=', $harnessSource);
        self::assertStringNotContainsString('ps -o pgid=', $helperSource);
        self::assertDoesNotMatchRegularExpression(
            '/BOUNDED_OWNED_PID=\$!\s*\n(?:.*\n){0,40}?_bounded_read_pgrp "\$BOUNDED_OWNED_PID"/',
            $helperSource,
            'Must not wait on live /proc observation of the child before recording PGID custody.',
        );
    }

    #[Test]
    public function bounded_helper_accepts_repeated_fast_true_without_status_126(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $work = sys_get_temp_dir() . '/waaseyaa_bounded_fast_' . $suffix;
        self::assertTrue(mkdir($work, 0o755, true));

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            root="$1"
            work="$2"
            # shellcheck source=tests/PackagedForm/lib/run-bounded.sh
            source "$root/tests/PackagedForm/lib/run-bounded.sh"
            BOUNDED_LOG_DIR="$work"
            cleanup() {
                reap_bounded_owned_child
                rm -rf -- "$work" || echo "warning: failed to remove $work" >&2
            }
            trap cleanup EXIT
            fails=0
            for i in $(seq 1 20); do
                set +e
                run_bounded "fast-${i}" 5 /bin/true
                st=$?
                set -e
                if [[ "$st" -eq 126 ]]; then
                    fails=$((fails + 1))
                elif [[ "$st" -ne 0 ]]; then
                    echo "unexpected status ${st} on fast-${i}" >&2
                    exit "$st"
                fi
            done
            if [[ "$fails" -ne 0 ]]; then
                echo "fast /bin/true produced ${fails}/20 status-126 refusals" >&2
                exit 1
            fi
            SH;

        $process = new Process(
            ['bash', '-c', $script, 'bounded-fast', $this->repoRoot, $work],
            null,
            null,
            null,
            60.0,
        );
        $exit = $process->run();

        self::assertSame(
            0,
            $exit,
            "20× run_bounded fast /bin/true must never refuse with status 126.\n"
            . $process->getOutput() . "\n" . $process->getErrorOutput(),
        );
        self::assertFileDoesNotExist($work);
    }

    #[Test]
    public function bounded_helper_reaps_descendant_after_delayed_observer_early_parent_exit(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $work = sys_get_temp_dir() . '/waaseyaa_bounded_delay_' . $suffix;
        $pidFile = sys_get_temp_dir() . '/waaseyaa_bounded_delay_pid_' . $suffix;
        self::assertTrue(mkdir($work, 0o755, true));

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            root="$1"
            work="$2"
            pid_file="$3"
            # shellcheck source=tests/PackagedForm/lib/run-bounded.sh
            source "$root/tests/PackagedForm/lib/run-bounded.sh"
            BOUNDED_LOG_DIR="$work"
            # Independent-review discriminator: delaying after spawn used to
            # miss /proc adoption, return 126, clear custody, and leak sleep.
            BOUNDED_TEST_POST_SPAWN_DELAY_MS=150
            cleanup() {
                reap_bounded_owned_child
                if [[ -f "$pid_file" ]]; then
                    desc="$(cat "$pid_file" 2>/dev/null || true)"
                    if [[ "$desc" =~ ^[1-9][0-9]*$ ]]; then
                        kill -TERM "$desc" 2>/dev/null || true
                        kill -KILL "$desc" 2>/dev/null || true
                    fi
                fi
                rm -rf -- "$work" || echo "warning: failed to remove $work" >&2
            }
            trap cleanup EXIT
            run_bounded delayed 5 bash -c 'sleep 20 & printf "%s\n" "$!" >"$1"; exit 0' _ "$pid_file"
            SH;

        $process = new Process(
            ['bash', '-c', $script, 'bounded-delay', $this->repoRoot, $work, $pidFile],
            null,
            null,
            null,
            20.0,
        );
        $exit = $process->run();

        $descendantPid = 0;
        if (is_file($pidFile)) {
            $descendantPid = (int) trim((string) file_get_contents($pidFile));
        }

        self::assertSame(
            0,
            $exit,
            "Delayed post-spawn observer must not refuse (126) or abandon custody.\n"
            . $process->getOutput() . "\n" . $process->getErrorOutput(),
        );
        self::assertStringNotContainsString('status-126', $process->getErrorOutput());
        self::assertStringNotContainsString('refused caller-group equivalence', $process->getErrorOutput());
        self::assertFileDoesNotExist($work);
        self::assertFileExists($pidFile);
        @unlink($pidFile);
        self::assertGreaterThan(1, $descendantPid);
        self::assertFalse(
            $this->pidIsAlive($descendantPid),
            "Descendant pid {$descendantPid} must not leak after delayed-observer early parent exit.",
        );
    }

    #[Test]
    public function bounded_helper_reaps_descendant_after_early_parent_exit(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $work = sys_get_temp_dir() . '/waaseyaa_bounded_early_' . $suffix;
        $pidFile = sys_get_temp_dir() . '/waaseyaa_bounded_early_pid_' . $suffix;
        self::assertTrue(mkdir($work, 0o755, true));

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            root="$1"
            work="$2"
            pid_file="$3"
            # shellcheck source=tests/PackagedForm/lib/run-bounded.sh
            source "$root/tests/PackagedForm/lib/run-bounded.sh"
            BOUNDED_LOG_DIR="$work"
            cleanup() {
                # Cleanup is best-effort and must never change this script's
                # exit status; report a leak on stderr instead (#2870).
                reap_bounded_owned_child
                if [[ -f "$pid_file" ]]; then
                    desc="$(cat "$pid_file" 2>/dev/null || true)"
                    if [[ "$desc" =~ ^[1-9][0-9]*$ ]]; then
                        kill -TERM "$desc" 2>/dev/null || true
                        kill -KILL "$desc" 2>/dev/null || true
                    fi
                fi
                rm -rf -- "$work" || echo "warning: failed to remove $work" >&2
            }
            trap cleanup EXIT
            # Exact independent-review reproducer: parent backgrounds a long
            # sleep and exits 0; custody must still reap the descendant.
            run_bounded early 5 bash -c 'sleep 20 & printf "%s\n" "$!" >"$1"; exit 0' _ "$pid_file"
            SH;

        $process = new Process(
            ['bash', '-c', $script, 'bounded-early', $this->repoRoot, $work, $pidFile],
            null,
            null,
            null,
            20.0,
        );
        $exit = $process->run();

        self::assertSame(
            0,
            $exit,
            "Early-parent-exit command must still return success.\n"
            . $process->getOutput() . "\n" . $process->getErrorOutput(),
        );
        self::assertFileDoesNotExist($work, 'Cleanup must remove the disposable work tree after reaping.');
        self::assertFileExists($pidFile, 'Descendant PID must be recorded outside the removed work tree.');
        $descendantPid = (int) trim((string) file_get_contents($pidFile));
        @unlink($pidFile);
        self::assertGreaterThan(1, $descendantPid);
        self::assertFalse(
            $this->pidIsAlive($descendantPid),
            "Descendant pid {$descendantPid} must not survive successful wrapper return and cleanup.",
        );
    }

    #[Test]
    public function bounded_helper_escalates_term_ignoring_timeout_child_to_kill(): void
    {
        if (!function_exists('pcntl_signal') || !defined('SIGTERM')) {
            self::markTestSkipped('TERM-ignore escalation fixture requires ext-pcntl (no Perl dependency).');
        }

        $suffix = bin2hex(random_bytes(8));
        $work = sys_get_temp_dir() . '/waaseyaa_bounded_kill_' . $suffix;
        $pidFile = sys_get_temp_dir() . '/waaseyaa_bounded_kill_pid_' . $suffix;
        $markerFile = sys_get_temp_dir() . '/waaseyaa_bounded_kill_term_' . $suffix;
        self::assertTrue(mkdir($work, 0o755, true));

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            root="$1"
            work="$2"
            pid_file="$3"
            marker_file="$4"
            php_bin="$5"
            # shellcheck source=tests/PackagedForm/lib/run-bounded.sh
            source "$root/tests/PackagedForm/lib/run-bounded.sh"
            BOUNDED_LOG_DIR="$work"
            cleanup() {
                # Cleanup is best-effort and must never change this script's
                # exit status; report a leak on stderr instead (#2870).
                reap_bounded_owned_child
                rm -rf -- "$work" || echo "warning: failed to remove $work" >&2
            }
            trap cleanup EXIT
            # First TERM writes a marker then installs SIG_IGN so timeout must
            # escalate to KILL. Use PHP_BINARY + pcntl (no Perl).
            run_bounded ignore-term 1 "$php_bin" -r '
              if (!function_exists("pcntl_signal") || !defined("SIGTERM")) {
                fwrite(STDERR, "pcntl required for TERM-ignore fixture\n");
                exit(2);
              }
              if (function_exists("pcntl_async_signals")) {
                pcntl_async_signals(true);
              }
              $marker = $argv[2];
              pcntl_signal(SIGTERM, static function () use ($marker): void {
                file_put_contents($marker, "TERM\n");
                pcntl_signal(SIGTERM, SIG_IGN);
              });
              file_put_contents($argv[1], (string) getmypid() . "\n");
              sleep(60);
            ' "$pid_file" "$marker_file"
            SH;

        $process = new Process(
            ['bash', '-c', $script, 'bounded-kill', $this->repoRoot, $work, $pidFile, $markerFile, PHP_BINARY],
            null,
            null,
            null,
            25.0,
        );
        $started = microtime(true);
        $exit = $process->run();
        $elapsed = microtime(true) - $started;

        self::assertSame(
            124,
            $exit,
            "TERM-ignoring child must still fail with timeout status 124 after KILL escalation.\n"
            . $process->getOutput() . "\n" . $process->getErrorOutput(),
        );
        self::assertStringContainsString(
            'Bounded deadline exceeded for ignore-term after 1s (sent TERM, then KILL).',
            $process->getErrorOutput(),
        );
        self::assertFileExists($markerFile, 'Child must record that SIGTERM arrived before KILL escalation.');
        self::assertSame("TERM\n", (string) file_get_contents($markerFile));
        @unlink($markerFile);
        self::assertLessThan(20.0, $elapsed, 'Escalation must finish within a broad upper bound.');
        self::assertFileDoesNotExist($work);
        self::assertFileExists($pidFile);
        $childPid = (int) trim((string) file_get_contents($pidFile));
        @unlink($pidFile);
        self::assertGreaterThan(1, $childPid);
        self::assertFalse(
            $this->pidIsAlive($childPid),
            "TERM-ignoring child pid {$childPid} must not remain after KILL escalation and cleanup.",
        );
    }

    #[Test]
    public function the_pull_request_pipeline_runs_the_proof(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString('name: ci/fresh-install-boot', $workflow);
        self::assertStringContainsString(self::HARNESS, $workflow);
    }

    private function pidIsAlive(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }
        $probe = new Process(
            ['bash', '-c', 'kill -0 "$1" 2>/dev/null', 'pid-alive', (string) $pid],
            null,
            null,
            null,
            5.0,
        );
        $probe->run();

        return $probe->getExitCode() === 0;
    }
}
