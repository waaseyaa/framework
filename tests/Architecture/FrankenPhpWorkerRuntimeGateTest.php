<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\Tests\Support\ReplacesProcessEnvironment;

/**
 * #2494. Hosted Framework CI must execute a real FrankenPHP worker against the
 * shipped production front controller. PHP-level simulation is not a substitute.
 */
#[CoversNothing]
final class FrankenPhpWorkerRuntimeGateTest extends TestCase
{
    use ReplacesProcessEnvironment;

    private const string PIN = 'tools/frankenphp-runtime-pin.json';
    private const string HARNESS = 'scripts/acceptance-frankenphp-worker.sh';
    private const string PROBE = 'tests/Acceptance/FrankenPhpWorker/probe.php';
    private const string LEAK = 'tests/Acceptance/FrankenPhpWorker/leak.php';
    private const string SEED = 'tests/Acceptance/FrankenPhpWorker/seed.php';
    private const string CONCURRENT = 'tests/Acceptance/FrankenPhpWorker/assert-concurrent-pids.py';
    private const string PROBE_CLASS = 'packages/frankenphp/src/WorkerAcceptance.php';

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    private function pin(): array
    {
        $decoded = json_decode((string) file_get_contents($this->repoRoot . '/' . self::PIN), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    #[Test]
    public function the_binary_pin_is_explicit_and_checksummed(): void
    {
        $pin = $this->pin();
        self::assertSame('v1.12.4', $pin['version']);
        self::assertSame('frankenphp-linux-x86_64', $pin['asset']);
        self::assertSame(
            'https://github.com/php/frankenphp/releases/download/v1.12.4/frankenphp-linux-x86_64',
            $pin['url'],
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $pin['sha256']);
        self::assertStringContainsString((string) $pin['version'], (string) $pin['url']);
        self::assertStringNotContainsString('get.frankenphp.dev', (string) $pin['url']);
    }

    #[Test]
    public function the_harness_and_test_only_fixtures_exist(): void
    {
        foreach ([self::HARNESS, self::PROBE, self::LEAK, self::SEED, self::PIN, self::PROBE_CLASS, self::CONCURRENT] as $relative) {
            self::assertFileExists($this->repoRoot . '/' . $relative, $relative);
        }
        self::assertTrue(is_executable($this->repoRoot . '/' . self::HARNESS), self::HARNESS . ' must be executable.');

        $harness = (string) file_get_contents($this->repoRoot . '/' . self::HARNESS);
        self::assertStringContainsString('FRANKENPHP_BINARY', $harness);
        self::assertStringContainsString('sha256sum', $harness);
        self::assertStringContainsString('public/index.php', $harness);
        self::assertStringContainsString('php-server', $harness);
        self::assertStringContainsString('Missing binary is not skippable', $harness);
        self::assertStringContainsString('assert-concurrent-pids.py', $harness);
        self::assertStringContainsString('WAASEYAA_STORAGE_PATH', $harness);
        self::assertStringContainsString('git status --porcelain', $harness);
        self::assertStringContainsString('public/storage', $harness);
        self::assertStringNotContainsString('AUTH_TOKEN_SECRET:-$WAASEYAA_APP_SECRET', $harness);
        self::assertStringNotContainsString('mkdir -p "$SESSION_DIR" "$INI_DIR" "$ROOT/storage"', $harness);

        $leak = (string) file_get_contents($this->repoRoot . '/' . self::LEAK);
        self::assertStringContainsString('WaaseyaaFrankenphpAcceptanceLeakStore', $leak);
        self::assertStringContainsString('static ?string $previousMark', $leak);
        self::assertStringContainsString('X-Waaseyaa-Leak-Previous', $leak);

        $autoload = json_decode((string) file_get_contents($this->repoRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $psr4 = json_encode($autoload['autoload'] ?? [], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Acceptance/FrankenPhpWorker', $psr4);
        self::assertStringNotContainsString('leak.php', $psr4);
    }

    #[Test]
    public function ordinary_ci_installs_the_pinned_binary_and_runs_the_worker_lane(): void
    {
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');
        self::assertStringContainsString('name: ci/frankenphp-worker', $workflow);
        self::assertStringContainsString(self::HARNESS, $workflow);
        self::assertStringContainsString('--inject-leak', $workflow);
        self::assertStringContainsString('sha256sum -c', $workflow);
        self::assertStringContainsString('chmod +x', $workflow);
        $checksumAt = strpos($workflow, 'sha256sum -c');
        $chmodAt = strpos($workflow, 'chmod +x "$FRANKENPHP_BIN"');
        self::assertIsInt($checksumAt);
        self::assertIsInt($chmodAt);
        self::assertLessThan($chmodAt, $checksumAt, 'Checksum verification must precede chmod/execution.');
        self::assertStringNotContainsString('get.frankenphp.dev', $workflow);
        self::assertStringNotContainsString('continue-on-error: true', substr($workflow, (int) strpos($workflow, 'frankenphp-worker')));
    }

    #[Test]
    public function the_front_controller_keeps_classic_fallback_and_fresh_kernels(): void
    {
        $front = (string) file_get_contents($this->repoRoot . '/public/index.php');
        self::assertStringContainsString("\$_SERVER['WAASEYAA_FRANKENPHP_WORKER'] ?? getenv('WAASEYAA_FRANKENPHP_WORKER')", $front);
        self::assertStringContainsString("if (!function_exists('frankenphp_handle_request'))", $front);
        self::assertStringNotContainsString("if (function_exists('frankenphp_handle_request'))", $front);
        self::assertStringContainsString('$handler();', $front);
        self::assertStringContainsString('new HttpKernel($projectRoot)', $front);
        self::assertStringContainsString('A fresh kernel is built per request', $front);
        self::assertStringContainsString('WAASEYAA_FRANKENPHP_ACCEPTANCE', $front);
        self::assertStringContainsString('worker-lane-v1', $front);
        self::assertStringContainsString('Waaseyaa\\\\FrankenPhp\\\\WorkerAcceptance', $front);
        self::assertStringNotContainsString('tests/Acceptance', $front);
        self::assertStringNotContainsString('WAASEYAA_FRANKENPHP_ACCEPTANCE_PROBE', $front);

        $harness = (string) file_get_contents($this->repoRoot . '/' . self::HARNESS);
        self::assertStringContainsString('env WAASEYAA_FRANKENPHP_WORKER "1"', $harness);
        self::assertStringContainsString('classic FrankenPHP returned an empty response body', $harness);
    }

    #[Test]
    public function missing_binary_is_an_infrastructure_failure(): void
    {
        $harness = $this->repoRoot . '/' . self::HARNESS;
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['FRANKENPHP_BINARY'] = '';
        // proc_open() REPLACED the child environment with $env; Symfony merges
        // onto the inherited one, so replacingEnv() pins every name $env does
        // not carry and the child sees exactly $env. The explicit empty
        // FRANKENPHP_BINARY above survives either way (an explicitly named key
        // wins the merge), so the trait is used here for the same semantics as
        // the sibling self-test case below, where withholding a name is what
        // actually differs. timeout: null — never time-bounded (#2491).
        $process = new Process(
            ['bash', $harness],
            $this->repoRoot,
            self::replacingEnv($env),
            null,
            null,
        );
        $status = $process->run();
        $stderr = $process->getErrorOutput();
        self::assertSame(1, $status);
        self::assertStringContainsString('FRANKENPHP_BINARY', $stderr);
        self::assertStringNotContainsString('skipped', strtolower($stderr));
    }

    #[Test]
    public function harness_self_test_fails_closed_on_pid_and_probe_mutations(): void
    {
        $harness = $this->repoRoot . '/' . self::HARNESS;
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        unset($env['FRANKENPHP_BINARY']);
        // The unset() above is the case replacingEnv() exists for: the harness
        // self-test branches on `[[ -x "${FRANKENPHP_BINARY:-}" ]]`, so a merged
        // environment would hand an exported binary path straight back to the
        // child this test deliberately withholds it from, and the child would
        // take the "binary present" branch instead. Both branches exit 0 today,
        // so this is about running the branch the fixture selected rather than
        // about a currently failing assertion.
        // timeout: null — this gate was never time-bounded (#2491).
        $process = new Process(
            ['bash', $harness, '--self-test'],
            $this->repoRoot,
            self::replacingEnv($env),
            null,
            null,
        );
        $status = $process->run();
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('concurrent PID missing/changed proofs failed closed', $stdout);
        self::assertStringContainsString('absent-tests / request-only / wrong-sapi / path-override / repeat proofs stayed inert', $stdout);
        self::assertStringContainsString('public/storage artifact is treated as a custody failure', $stdout);
    }
}
