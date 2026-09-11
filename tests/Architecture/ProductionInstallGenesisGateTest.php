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
        self::assertStringContainsString('setsid timeout', $helperSource);
        self::assertStringContainsString('Bounded deadline exceeded for', $helperSource);
        self::assertStringContainsString('kill -TERM', $helperSource);
        self::assertStringContainsString('kill -KILL', $helperSource);
        self::assertStringContainsString('DEADLINE_COMPOSER=', $harnessSource);
        self::assertStringContainsString('DEADLINE_INSTALL=', $harnessSource);
    }

    #[Test]
    public function bounded_helper_terminates_a_stalled_child_with_deterministic_diagnostics(): void
    {
        $work = sys_get_temp_dir() . '/waaseyaa_bounded_stall_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($work, 0o755, true));

        $marker = $work . '/stall.marker';
        $script = <<<'SH'
            #!/usr/bin/env bash
            set -euo pipefail
            root="$1"
            work="$2"
            marker="$3"
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
            # Stall far past the one-second deadline; write a marker first so a
            # hang without timeout is distinguishable from never starting.
            run_bounded stall 1 bash -c 'printf started >"$1"; exec sleep 60' _ "$marker"
            SH;

        $process = new Process(
            ['bash', '-c', $script, 'bounded-stall', $this->repoRoot, $work, $marker],
            null,
            null,
            null,
            15.0,
        );
        $started = microtime(true);
        $exit = $process->run();
        $elapsed = microtime(true) - $started;

        self::assertSame(
            124,
            $exit,
            "Stalled child must fail with timeout status 124.\n"
            . $process->getOutput() . "\n" . $process->getErrorOutput(),
        );
        self::assertStringContainsString(
            'Bounded deadline exceeded for stall after 1s (sent TERM, then KILL).',
            $process->getErrorOutput(),
        );
        self::assertGreaterThanOrEqual(0.9, $elapsed);
        self::assertLessThan(12.0, $elapsed, 'Timeout must complete well under the Process outer bound.');
        self::assertFileDoesNotExist($work, 'Cleanup must remove the disposable work tree after reaping.');
        self::assertFalse(
            $this->processGroupStillAliveFromMarker($marker),
            'No stalled sleep child may remain after TERM→KILL and EXIT reaping.',
        );
    }

    #[Test]
    public function the_pull_request_pipeline_runs_the_proof(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString('name: ci/fresh-install-boot', $workflow);
        self::assertStringContainsString(self::HARNESS, $workflow);
    }

    private function processGroupStillAliveFromMarker(string $marker): bool
    {
        if (!is_file($marker)) {
            // Timeout may have killed the child before it wrote the marker; that
            // still means no live stall remains under our owned work tree.
            return false;
        }

        $probe = new Process(
            ['bash', '-c', 'pgrep -af "sleep 60" || true'],
            null,
            null,
            null,
            5.0,
        );
        $probe->run();

        return str_contains($probe->getOutput(), 'sleep 60');
    }
}
