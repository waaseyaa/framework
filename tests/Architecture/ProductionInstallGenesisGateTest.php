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
 *
 * Process fixtures track only exact PIDs/PGIDs minted by the current
 * invocation and terminate those identities in EXIT + PHP finally — never
 * broad pattern process kills.
 */
#[CoversNothing]
final class ProductionInstallGenesisGateTest extends TestCase
{
    private const string HARNESS = 'tests/PackagedForm/check-production-install-genesis';

    private const string PROBE = 'tests/ProductionInstallGenesis/probe.php';

    private const string BOUNDED_HELPER = 'tests/PackagedForm/lib/run-bounded.sh';

    private string $repoRoot;

    /** @var list<array{role: string, id: int, kind: string, action: string, alive_after: bool}> */
    private array $lastExactCleanupEvidence = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->lastExactCleanupEvidence = [];
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
        $gateSource = (string) file_get_contents(__FILE__);

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

        foreach (['p' . 'kill', 'kill' . 'all'] as $broad) {
            self::assertStringNotContainsString($broad, $helperSource);
            self::assertStringNotContainsString($broad, $harnessSource);
            // Scan this file without matching this allowlisted probe itself:
            // forbid real argv / shell invocations only.
            self::assertDoesNotMatchRegularExpression(
                '/(?:Process\(\s*\[\s*[\'"]' . preg_quote($broad, '/') . '[\'"]|[\'"]' . preg_quote($broad, '/') . '[\'"]\s+-)/',
                $gateSource,
                'Architecture process fixtures must not invoke broad process cleanup.',
            );
        }
    }

    #[Test]
    public function bounded_helper_accepts_repeated_fast_true_without_status_126(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $work = sys_get_temp_dir() . '/waaseyaa_bounded_fast_' . $suffix;
        $evidenceFile = sys_get_temp_dir() . '/waaseyaa_bounded_fast_evidence_' . $suffix;
        self::assertTrue(mkdir($work, 0o755, true));

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            root="$1"
            work="$2"
            evidence_file="$3"
            # shellcheck source=tests/PackagedForm/lib/run-bounded.sh
            source "$root/tests/PackagedForm/lib/run-bounded.sh"
            BOUNDED_LOG_DIR="$work"
            cleanup() {
                {
                    [[ -n "${BOUNDED_OWNED_PID:-}" ]] && printf 'owned-pid=%s\n' "$BOUNDED_OWNED_PID"
                    [[ -n "${BOUNDED_OWNED_PGID:-}" ]] && printf 'owned-pgid=%s\n' "$BOUNDED_OWNED_PGID"
                } >>"$evidence_file" 2>/dev/null || true
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
            ['bash', '-c', $script, 'bounded-fast', $this->repoRoot, $work, $evidenceFile],
            null,
            null,
            null,
            60.0,
        );

        $exit = 1;
        $stdout = '';
        $stderr = '';
        try {
            $exit = $process->run();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
        } finally {
            $this->finalizeExactIdentities(
                process: $process,
                work: $work,
                evidenceFile: $evidenceFile,
            );
        }

        self::assertSame(
            0,
            $exit,
            "20× run_bounded fast /bin/true must never refuse with status 126.\n"
            . $stdout . "\n" . $stderr . "\n"
            . $this->formatExactCleanupEvidence(),
        );
        self::assertFileDoesNotExist($work);
    }

    #[Test]
    public function bounded_helper_reaps_descendant_after_delayed_observer_early_parent_exit(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $work = sys_get_temp_dir() . '/waaseyaa_bounded_delay_' . $suffix;
        $pidFile = sys_get_temp_dir() . '/waaseyaa_bounded_delay_pid_' . $suffix;
        $evidenceFile = sys_get_temp_dir() . '/waaseyaa_bounded_delay_evidence_' . $suffix;
        $leakMarkerFile = sys_get_temp_dir() . '/waaseyaa_bounded_delay_leak_' . $suffix;
        self::assertTrue(mkdir($work, 0o755, true));

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            root="$1"
            work="$2"
            pid_file="$3"
            evidence_file="$4"
            leak_marker_file="$5"
            # shellcheck source=tests/PackagedForm/lib/run-bounded.sh
            source "$root/tests/PackagedForm/lib/run-bounded.sh"
            BOUNDED_LOG_DIR="$work"
            # Independent-review discriminator: delaying after spawn used to
            # miss /proc adoption, return 126, clear custody, and leak sleep.
            BOUNDED_TEST_POST_SPAWN_DELAY_MS=150
            cleanup() {
                {
                    [[ -n "${BOUNDED_OWNED_PID:-}" ]] && printf 'owned-pid=%s\n' "$BOUNDED_OWNED_PID"
                    [[ -n "${BOUNDED_OWNED_PGID:-}" ]] && printf 'owned-pgid=%s\n' "$BOUNDED_OWNED_PGID"
                    if [[ -f "$pid_file" ]]; then
                        desc="$(tr -d '[:space:]' <"$pid_file" 2>/dev/null || true)"
                        if [[ "$desc" =~ ^[1-9][0-9]*$ ]]; then
                            printf 'tracked-pid=%s\n' "$desc"
                        fi
                    fi
                } >>"$evidence_file" 2>/dev/null || true
                reap_bounded_owned_child
                # Emergency exact-PID cleanup must not mask a helper leak: write a
                # unique leak marker BEFORE killing, then kill only that exact PID.
                if [[ -f "$pid_file" ]]; then
                    desc="$(tr -d '[:space:]' <"$pid_file" 2>/dev/null || true)"
                    if [[ "$desc" =~ ^[1-9][0-9]*$ ]]; then
                        if kill -0 "$desc" 2>/dev/null; then
                            printf 'bounded-helper-descendant-leak tracked-pid=%s\n' "$desc" >"$leak_marker_file"
                            printf 'emergency-cleanup-leak: tracked-pid=%s\n' "$desc" | tee -a "$evidence_file" >&2
                            kill -TERM "$desc" 2>/dev/null || true
                            kill -KILL "$desc" 2>/dev/null || true
                            if kill -0 "$desc" 2>/dev/null; then
                                printf 'emergency-cleanup: tracked-pid=%s killed alive_after=yes\n' "$desc" >&2
                            else
                                printf 'emergency-cleanup: tracked-pid=%s killed alive_after=no\n' "$desc" >&2
                            fi
                        else
                            printf 'emergency-cleanup: tracked-pid=%s already-dead\n' "$desc" | tee -a "$evidence_file" >&2
                        fi
                    fi
                fi
                rm -rf -- "$work" || echo "warning: failed to remove $work" >&2
            }
            trap cleanup EXIT
            set +e
            run_bounded delayed 5 bash -c 'sleep 20 & printf "%s\n" "$!" >"$1"; exit 0' _ "$pid_file"
            rb_status=$?
            set -e
            if [[ ! -f "$pid_file" ]]; then
                printf 'bounded-helper-descendant-leak missing-pid-file\n' >"$leak_marker_file"
                printf 'helper-leak: missing-pid-file-after-run_bounded\n' | tee -a "$evidence_file" >&2
                exit 1
            fi
            desc="$(tr -d '[:space:]' <"$pid_file" 2>/dev/null || true)"
            if [[ ! "$desc" =~ ^[1-9][0-9]*$ ]]; then
                printf 'bounded-helper-descendant-leak invalid-pid-file\n' >"$leak_marker_file"
                printf 'helper-leak: invalid-pid-file-after-run_bounded\n' | tee -a "$evidence_file" >&2
                exit 1
            fi
            # Acceptance discriminator: run_bounded itself must have reaped the
            # descendant before the emergency EXIT trap runs.
            if kill -0 "$desc" 2>/dev/null; then
                printf 'bounded-helper-descendant-leak tracked-pid=%s still-alive-after-run_bounded\n' "$desc" >"$leak_marker_file"
                printf 'helper-leak: tracked-pid=%s still-alive-after-run_bounded\n' "$desc" | tee -a "$evidence_file" >&2
                exit 1
            fi
            printf 'helper-reaped: tracked-pid=%s dead-after-run_bounded\n' "$desc" | tee -a "$evidence_file" >&2
            exit "$rb_status"
            SH;

        $process = new Process(
            ['bash', '-c', $script, 'bounded-delay', $this->repoRoot, $work, $pidFile, $evidenceFile, $leakMarkerFile],
            null,
            null,
            null,
            20.0,
        );

        $exit = 1;
        $stdout = '';
        $stderr = '';
        $evidenceText = '';
        $descendantPid = 0;
        $leakMarkerPresent = false;
        $leakMarkerBody = '';
        try {
            $exit = $process->run();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            if (is_file($pidFile)) {
                $descendantPid = (int) trim((string) file_get_contents($pidFile));
            }
            if (is_file($evidenceFile)) {
                $evidenceText = (string) file_get_contents($evidenceFile);
            }
            if (is_file($leakMarkerFile)) {
                $leakMarkerPresent = true;
                $leakMarkerBody = (string) file_get_contents($leakMarkerFile);
            }
        } finally {
            $this->finalizeExactIdentities(
                process: $process,
                work: $work,
                evidenceFile: $evidenceFile,
                pidFile: $pidFile,
                extraFiles: [$leakMarkerFile],
            );
        }

        self::assertSame(
            0,
            $exit,
            "Delayed post-spawn observer must not refuse (126) or abandon custody.\n"
            . $stdout . "\n" . $stderr . "\n" . $evidenceText . "\n"
            . $this->formatExactCleanupEvidence(),
        );
        self::assertStringNotContainsString('status-126', $stderr);
        self::assertStringNotContainsString('refused caller-group equivalence', $stderr);
        self::assertFileDoesNotExist($work);
        self::assertGreaterThan(1, $descendantPid);
        self::assertFalse(
            $leakMarkerPresent,
            "Unique leak marker must be absent (fallback must not mask a helper leak).\n"
            . $leakMarkerBody . "\n" . $stderr . "\n" . $evidenceText,
        );
        $this->assertHelperReapedDescendantBeforeEmergency($descendantPid, $stderr, $evidenceText);
    }

    #[Test]
    public function bounded_helper_reaps_descendant_after_early_parent_exit(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $work = sys_get_temp_dir() . '/waaseyaa_bounded_early_' . $suffix;
        $pidFile = sys_get_temp_dir() . '/waaseyaa_bounded_early_pid_' . $suffix;
        $evidenceFile = sys_get_temp_dir() . '/waaseyaa_bounded_early_evidence_' . $suffix;
        $leakMarkerFile = sys_get_temp_dir() . '/waaseyaa_bounded_early_leak_' . $suffix;
        self::assertTrue(mkdir($work, 0o755, true));

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            root="$1"
            work="$2"
            pid_file="$3"
            evidence_file="$4"
            leak_marker_file="$5"
            # shellcheck source=tests/PackagedForm/lib/run-bounded.sh
            source "$root/tests/PackagedForm/lib/run-bounded.sh"
            BOUNDED_LOG_DIR="$work"
            cleanup() {
                # Cleanup is best-effort and must never change this script's
                # exit status; report a leak on stderr instead (#2870).
                {
                    [[ -n "${BOUNDED_OWNED_PID:-}" ]] && printf 'owned-pid=%s\n' "$BOUNDED_OWNED_PID"
                    [[ -n "${BOUNDED_OWNED_PGID:-}" ]] && printf 'owned-pgid=%s\n' "$BOUNDED_OWNED_PGID"
                    if [[ -f "$pid_file" ]]; then
                        desc="$(tr -d '[:space:]' <"$pid_file" 2>/dev/null || true)"
                        if [[ "$desc" =~ ^[1-9][0-9]*$ ]]; then
                            printf 'tracked-pid=%s\n' "$desc"
                        fi
                    fi
                } >>"$evidence_file" 2>/dev/null || true
                reap_bounded_owned_child
                # Emergency exact-PID cleanup must not mask a helper leak: write a
                # unique leak marker BEFORE killing, then kill only that exact PID.
                if [[ -f "$pid_file" ]]; then
                    desc="$(tr -d '[:space:]' <"$pid_file" 2>/dev/null || true)"
                    if [[ "$desc" =~ ^[1-9][0-9]*$ ]]; then
                        if kill -0 "$desc" 2>/dev/null; then
                            printf 'bounded-helper-descendant-leak tracked-pid=%s\n' "$desc" >"$leak_marker_file"
                            printf 'emergency-cleanup-leak: tracked-pid=%s\n' "$desc" | tee -a "$evidence_file" >&2
                            kill -TERM "$desc" 2>/dev/null || true
                            kill -KILL "$desc" 2>/dev/null || true
                            if kill -0 "$desc" 2>/dev/null; then
                                printf 'emergency-cleanup: tracked-pid=%s killed alive_after=yes\n' "$desc" >&2
                            else
                                printf 'emergency-cleanup: tracked-pid=%s killed alive_after=no\n' "$desc" >&2
                            fi
                        else
                            printf 'emergency-cleanup: tracked-pid=%s already-dead\n' "$desc" | tee -a "$evidence_file" >&2
                        fi
                    fi
                fi
                rm -rf -- "$work" || echo "warning: failed to remove $work" >&2
            }
            trap cleanup EXIT
            # Exact independent-review reproducer: parent backgrounds a long
            # sleep and exits 0; custody must still reap the descendant.
            set +e
            run_bounded early 5 bash -c 'sleep 20 & printf "%s\n" "$!" >"$1"; exit 0' _ "$pid_file"
            rb_status=$?
            set -e
            if [[ ! -f "$pid_file" ]]; then
                printf 'bounded-helper-descendant-leak missing-pid-file\n' >"$leak_marker_file"
                printf 'helper-leak: missing-pid-file-after-run_bounded\n' | tee -a "$evidence_file" >&2
                exit 1
            fi
            desc="$(tr -d '[:space:]' <"$pid_file" 2>/dev/null || true)"
            if [[ ! "$desc" =~ ^[1-9][0-9]*$ ]]; then
                printf 'bounded-helper-descendant-leak invalid-pid-file\n' >"$leak_marker_file"
                printf 'helper-leak: invalid-pid-file-after-run_bounded\n' | tee -a "$evidence_file" >&2
                exit 1
            fi
            # Acceptance discriminator: run_bounded itself must have reaped the
            # descendant before the emergency EXIT trap runs.
            if kill -0 "$desc" 2>/dev/null; then
                printf 'bounded-helper-descendant-leak tracked-pid=%s still-alive-after-run_bounded\n' "$desc" >"$leak_marker_file"
                printf 'helper-leak: tracked-pid=%s still-alive-after-run_bounded\n' "$desc" | tee -a "$evidence_file" >&2
                exit 1
            fi
            printf 'helper-reaped: tracked-pid=%s dead-after-run_bounded\n' "$desc" | tee -a "$evidence_file" >&2
            exit "$rb_status"
            SH;

        $process = new Process(
            ['bash', '-c', $script, 'bounded-early', $this->repoRoot, $work, $pidFile, $evidenceFile, $leakMarkerFile],
            null,
            null,
            null,
            20.0,
        );

        $exit = 1;
        $stdout = '';
        $stderr = '';
        $evidenceText = '';
        $descendantPid = 0;
        $leakMarkerPresent = false;
        $leakMarkerBody = '';
        try {
            $exit = $process->run();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            if (is_file($pidFile)) {
                $descendantPid = (int) trim((string) file_get_contents($pidFile));
            }
            if (is_file($evidenceFile)) {
                $evidenceText = (string) file_get_contents($evidenceFile);
            }
            if (is_file($leakMarkerFile)) {
                $leakMarkerPresent = true;
                $leakMarkerBody = (string) file_get_contents($leakMarkerFile);
            }
        } finally {
            $this->finalizeExactIdentities(
                process: $process,
                work: $work,
                evidenceFile: $evidenceFile,
                pidFile: $pidFile,
                extraFiles: [$leakMarkerFile],
            );
        }

        self::assertSame(
            0,
            $exit,
            "Early-parent-exit command must still return success.\n"
            . $stdout . "\n" . $stderr . "\n" . $evidenceText . "\n"
            . $this->formatExactCleanupEvidence(),
        );
        self::assertFileDoesNotExist($work, 'Cleanup must remove the disposable work tree after reaping.');
        self::assertGreaterThan(1, $descendantPid);
        self::assertFalse(
            $leakMarkerPresent,
            "Unique leak marker must be absent (fallback must not mask a helper leak).\n"
            . $leakMarkerBody . "\n" . $stderr . "\n" . $evidenceText,
        );
        $this->assertHelperReapedDescendantBeforeEmergency($descendantPid, $stderr, $evidenceText);
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
        $evidenceFile = sys_get_temp_dir() . '/waaseyaa_bounded_kill_evidence_' . $suffix;
        $leakMarkerFile = sys_get_temp_dir() . '/waaseyaa_bounded_kill_leak_' . $suffix;
        self::assertTrue(mkdir($work, 0o755, true));

        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            root="$1"
            work="$2"
            pid_file="$3"
            marker_file="$4"
            evidence_file="$5"
            leak_marker_file="$6"
            php_bin="$7"
            # shellcheck source=tests/PackagedForm/lib/run-bounded.sh
            source "$root/tests/PackagedForm/lib/run-bounded.sh"
            BOUNDED_LOG_DIR="$work"
            cleanup() {
                # Cleanup is best-effort and must never change this script's
                # exit status; report a leak on stderr instead (#2870).
                {
                    [[ -n "${BOUNDED_OWNED_PID:-}" ]] && printf 'owned-pid=%s\n' "$BOUNDED_OWNED_PID"
                    [[ -n "${BOUNDED_OWNED_PGID:-}" ]] && printf 'owned-pgid=%s\n' "$BOUNDED_OWNED_PGID"
                    if [[ -f "$pid_file" ]]; then
                        desc="$(tr -d '[:space:]' <"$pid_file" 2>/dev/null || true)"
                        if [[ "$desc" =~ ^[1-9][0-9]*$ ]]; then
                            printf 'tracked-pid=%s\n' "$desc"
                        fi
                    fi
                } >>"$evidence_file" 2>/dev/null || true
                reap_bounded_owned_child
                # Emergency exact-PID cleanup must not mask a helper leak: write a
                # unique leak marker BEFORE killing, then kill only that exact PID.
                if [[ -f "$pid_file" ]]; then
                    desc="$(tr -d '[:space:]' <"$pid_file" 2>/dev/null || true)"
                    if [[ "$desc" =~ ^[1-9][0-9]*$ ]]; then
                        if kill -0 "$desc" 2>/dev/null; then
                            printf 'bounded-helper-descendant-leak tracked-pid=%s\n' "$desc" >"$leak_marker_file"
                            printf 'emergency-cleanup-leak: tracked-pid=%s\n' "$desc" | tee -a "$evidence_file" >&2
                            kill -TERM "$desc" 2>/dev/null || true
                            kill -KILL "$desc" 2>/dev/null || true
                            if kill -0 "$desc" 2>/dev/null; then
                                printf 'emergency-cleanup: tracked-pid=%s killed alive_after=yes\n' "$desc" >&2
                            else
                                printf 'emergency-cleanup: tracked-pid=%s killed alive_after=no\n' "$desc" >&2
                            fi
                        else
                            printf 'emergency-cleanup: tracked-pid=%s already-dead\n' "$desc" | tee -a "$evidence_file" >&2
                        fi
                    fi
                fi
                rm -rf -- "$work" || echo "warning: failed to remove $work" >&2
            }
            trap cleanup EXIT
            # TERM handler writes a marker then continues a persistent loop until
            # KILL. A lone sleep(60) returns after SIGTERM and exits normally,
            # which would not prove timeout's KILL escalation. Use PHP_BINARY +
            # pcntl (no Perl).
            set +e
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
              while (true) {
                sleep(1);
              }
            ' "$pid_file" "$marker_file"
            rb_status=$?
            set -e
            if [[ ! -f "$pid_file" ]]; then
                printf 'bounded-helper-descendant-leak missing-pid-file\n' >"$leak_marker_file"
                printf 'helper-leak: missing-pid-file-after-run_bounded\n' | tee -a "$evidence_file" >&2
                exit 1
            fi
            desc="$(tr -d '[:space:]' <"$pid_file" 2>/dev/null || true)"
            if [[ ! "$desc" =~ ^[1-9][0-9]*$ ]]; then
                printf 'bounded-helper-descendant-leak invalid-pid-file\n' >"$leak_marker_file"
                printf 'helper-leak: invalid-pid-file-after-run_bounded\n' | tee -a "$evidence_file" >&2
                exit 1
            fi
            # Acceptance discriminator: run_bounded/timeout itself must have
            # removed the child before the emergency EXIT trap runs.
            if kill -0 "$desc" 2>/dev/null; then
                printf 'bounded-helper-descendant-leak tracked-pid=%s still-alive-after-run_bounded\n' "$desc" >"$leak_marker_file"
                printf 'helper-leak: tracked-pid=%s still-alive-after-run_bounded\n' "$desc" | tee -a "$evidence_file" >&2
                exit 1
            fi
            printf 'helper-reaped: tracked-pid=%s dead-after-run_bounded\n' "$desc" | tee -a "$evidence_file" >&2
            exit "$rb_status"
            SH;

        $process = new Process(
            ['bash', '-c', $script, 'bounded-kill', $this->repoRoot, $work, $pidFile, $markerFile, $evidenceFile, $leakMarkerFile, PHP_BINARY],
            null,
            null,
            null,
            25.0,
        );

        $exit = 1;
        $stdout = '';
        $stderr = '';
        $evidenceText = '';
        $elapsed = 0.0;
        $childPid = 0;
        $marker = '';
        $leakMarkerPresent = false;
        $leakMarkerBody = '';
        try {
            $started = microtime(true);
            $exit = $process->run();
            $elapsed = microtime(true) - $started;
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
            if (is_file($pidFile)) {
                $childPid = (int) trim((string) file_get_contents($pidFile));
            }
            if (is_file($markerFile)) {
                $marker = (string) file_get_contents($markerFile);
            }
            if (is_file($evidenceFile)) {
                $evidenceText = (string) file_get_contents($evidenceFile);
            }
            if (is_file($leakMarkerFile)) {
                $leakMarkerPresent = true;
                $leakMarkerBody = (string) file_get_contents($leakMarkerFile);
            }
        } finally {
            $this->finalizeExactIdentities(
                process: $process,
                work: $work,
                evidenceFile: $evidenceFile,
                pidFile: $pidFile,
                extraFiles: [$markerFile, $leakMarkerFile],
            );
        }

        self::assertSame(
            124,
            $exit,
            "TERM-ignoring child must still fail with timeout status 124 after KILL escalation.\n"
            . $stdout . "\n" . $stderr . "\n" . $evidenceText . "\n"
            . $this->formatExactCleanupEvidence(),
        );
        self::assertStringContainsString(
            'Bounded deadline exceeded for ignore-term after 1s (sent TERM, then KILL).',
            $stderr,
        );
        self::assertSame("TERM\n", $marker, 'Child must record that SIGTERM arrived before KILL escalation.');
        self::assertFalse(
            $leakMarkerPresent,
            "Unique leak marker must be absent after TERM→KILL escalation.\n"
            . $leakMarkerBody . "\n" . $stderr . "\n" . $evidenceText,
        );
        self::assertLessThan(20.0, $elapsed, 'Escalation must finish within a broad upper bound.');
        self::assertFileDoesNotExist($work);
        self::assertGreaterThan(1, $childPid);
        $this->assertHelperReapedDescendantBeforeEmergency($childPid, $stderr, $evidenceText);
    }

    #[Test]
    public function the_pull_request_pipeline_runs_the_proof(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString('name: ci/fresh-install-boot', $workflow);
        self::assertStringContainsString(self::HARNESS, $workflow);
    }

    /**
     * Always terminate only identities recorded by this invocation, even when
     * assertions fail or the wrapper is still running.
     *
     * @param list<string> $extraFiles
     */
    private function finalizeExactIdentities(
        Process $process,
        string $work,
        string $evidenceFile,
        ?string $pidFile = null,
        array $extraFiles = [],
    ): void {
        if ($process->isRunning()) {
            $wrapperPid = $process->getPid();
            if (is_int($wrapperPid) && $wrapperPid > 1) {
                $this->lastExactCleanupEvidence[] = $this->terminateExactPid($wrapperPid, 'phpunit-wrapper-pid');
            }
            $process->stop(0.2, defined('SIGKILL') ? SIGKILL : 9);
        }

        foreach ($this->readExactIdentities($evidenceFile, $pidFile) as $identity) {
            if ($identity['kind'] === 'pgid') {
                $this->lastExactCleanupEvidence[] = $this->terminateExactPgid($identity['id'], $identity['role']);
            } else {
                $this->lastExactCleanupEvidence[] = $this->terminateExactPid($identity['id'], $identity['role']);
            }
        }

        foreach (array_filter([$pidFile, $evidenceFile, ...$extraFiles]) as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($work)) {
            // Best-effort tree removal only after exact process identities were addressed.
            $rm = new Process(['rm', '-rf', '--', $work], null, null, null, 5.0);
            $rm->run();
        }
    }

    /**
     * @return list<array{role: string, id: int, kind: string}>
     */
    private function readExactIdentities(string $evidenceFile, ?string $pidFile): array
    {
        $identities = [];
        $seen = [];

        $add = static function (string $role, int $id, string $kind) use (&$identities, &$seen): void {
            if ($id <= 1) {
                return;
            }
            $key = $kind . ':' . $id;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $identities[] = ['role' => $role, 'id' => $id, 'kind' => $kind];
        };

        if (is_file($evidenceFile)) {
            $lines = file($evidenceFile, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $line) {
                if (preg_match('/^owned-pid=([1-9][0-9]*)$/', $line, $m) === 1) {
                    $add('owned-pid', (int) $m[1], 'pid');
                } elseif (preg_match('/^owned-pgid=([1-9][0-9]*)$/', $line, $m) === 1) {
                    $add('owned-pgid', (int) $m[1], 'pgid');
                } elseif (preg_match('/^tracked-pid=([1-9][0-9]*)$/', $line, $m) === 1) {
                    $add('tracked-pid', (int) $m[1], 'pid');
                }
            }
        }

        if (is_string($pidFile) && is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            $add('pid-file', $pid, 'pid');
        }

        return $identities;
    }

    /**
     * @return array{role: string, id: int, kind: string, action: string, alive_after: bool}
     */
    private function terminateExactPid(int $pid, string $role): array
    {
        if ($pid <= 1) {
            return [
                'role' => $role,
                'id' => $pid,
                'kind' => 'pid',
                'action' => 'skip-invalid',
                'alive_after' => false,
            ];
        }

        if (!$this->pidIsAlive($pid)) {
            return [
                'role' => $role,
                'id' => $pid,
                'kind' => 'pid',
                'action' => 'already-dead',
                'alive_after' => false,
            ];
        }

        $term = new Process(['kill', '-TERM', (string) $pid], null, null, null, 5.0);
        $term->run();
        usleep(100_000);
        $action = 'term';
        if ($this->pidIsAlive($pid)) {
            $kill = new Process(['kill', '-KILL', (string) $pid], null, null, null, 5.0);
            $kill->run();
            $action = 'term-then-kill';
        }

        return [
            'role' => $role,
            'id' => $pid,
            'kind' => 'pid',
            'action' => $action,
            'alive_after' => $this->pidIsAlive($pid),
        ];
    }

    /**
     * @return array{role: string, id: int, kind: string, action: string, alive_after: bool}
     */
    private function terminateExactPgid(int $pgid, string $role): array
    {
        if ($pgid <= 1) {
            return [
                'role' => $role,
                'id' => $pgid,
                'kind' => 'pgid',
                'action' => 'skip-invalid',
                'alive_after' => false,
            ];
        }

        if (!$this->pgidIsAlive($pgid)) {
            return [
                'role' => $role,
                'id' => $pgid,
                'kind' => 'pgid',
                'action' => 'already-dead',
                'alive_after' => false,
            ];
        }

        $term = new Process(['kill', '-TERM', '--', '-' . $pgid], null, null, null, 5.0);
        $term->run();
        usleep(100_000);
        $action = 'term';
        if ($this->pgidIsAlive($pgid)) {
            $kill = new Process(['kill', '-KILL', '--', '-' . $pgid], null, null, null, 5.0);
            $kill->run();
            $action = 'term-then-kill';
        }

        return [
            'role' => $role,
            'id' => $pgid,
            'kind' => 'pgid',
            'action' => $action,
            'alive_after' => $this->pgidIsAlive($pgid),
        ];
    }

    private function formatExactCleanupEvidence(): string
    {
        if ($this->lastExactCleanupEvidence === []) {
            return 'exact-cleanup-evidence: (none yet; see bash exact-cleanup lines / finally)';
        }

        $lines = ['exact-cleanup-evidence:'];
        foreach ($this->lastExactCleanupEvidence as $row) {
            $lines[] = sprintf(
                '  %s %s=%d action=%s alive_after=%s',
                $row['role'],
                $row['kind'],
                $row['id'],
                $row['action'],
                $row['alive_after'] ? 'yes' : 'no',
            );
        }

        return implode("\n", $lines);
    }

    private function exactCleanupEvidenceMentions(int $id, string $kind): bool
    {
        foreach ($this->lastExactCleanupEvidence as $row) {
            if ($row['id'] === $id && $row['kind'] === $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * Acceptance: run_bounded itself removed the tracked descendant before any
     * emergency exact-PID trap. Emergency/PHP finally may still kill a live
     * leak (exact PID only) but must leave leak markers that fail this assert.
     */
    private function assertHelperReapedDescendantBeforeEmergency(
        int $trackedPid,
        string $stderr,
        string $evidenceText,
    ): void {
        $blob = $stderr . "\n" . $evidenceText . "\n" . $this->formatExactCleanupEvidence();

        self::assertMatchesRegularExpression(
            '/helper-reaped: tracked-pid=' . preg_quote((string) $trackedPid, '/') . ' dead-after-run_bounded/',
            $blob,
            "Acceptance requires run_bounded itself removed descendant {$trackedPid} before emergency cleanup.\n"
            . $blob,
        );
        self::assertStringNotContainsString(
            'helper-leak:',
            $blob,
            "Helper leak marker must fail the test (emergency cleanup must not mask it).\n" . $blob,
        );
        self::assertStringNotContainsString(
            'emergency-cleanup-leak:',
            $blob,
            "Emergency cleanup found a live descendant — helper leaked; marker must fail the test.\n" . $blob,
        );
        self::assertFalse(
            $this->pidIsAlive($trackedPid),
            "Tracked pid {$trackedPid} must be dead after helper reap and any emergency exact-PID cleanup.\n"
            . $blob,
        );
        self::assertTrue(
            $this->exactCleanupEvidenceMentions($trackedPid, 'pid'),
            "PHP finally must still record exact-identity handling for tracked pid {$trackedPid}.\n" . $blob,
        );

        foreach ($this->lastExactCleanupEvidence as $row) {
            if ($row['id'] === $trackedPid && $row['kind'] === 'pid') {
                self::assertSame(
                    'already-dead',
                    $row['action'],
                    "PHP finally must not mask a helper leak by killing live pid {$trackedPid}.\n" . $blob,
                );
            }
        }
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

    private function pgidIsAlive(int $pgid): bool
    {
        if ($pgid <= 1) {
            return false;
        }
        $probe = new Process(
            ['bash', '-c', 'kill -0 -- "-$1" 2>/dev/null', 'pgid-alive', (string) $pgid],
            null,
            null,
            null,
            5.0,
        );
        $probe->run();

        return $probe->getExitCode() === 0;
    }
}
