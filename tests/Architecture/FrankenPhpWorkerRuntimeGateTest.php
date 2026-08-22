<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2494. Hosted Framework CI must execute a real FrankenPHP worker against the
 * shipped production front controller. PHP-level simulation is not a substitute.
 */
#[CoversNothing]
final class FrankenPhpWorkerRuntimeGateTest extends TestCase
{
    private const string PIN = 'tools/frankenphp-runtime-pin.json';
    private const string HARNESS = 'scripts/acceptance-frankenphp-worker.sh';
    private const string PROBE = 'tests/Acceptance/FrankenPhpWorker/probe.php';
    private const string LEAK = 'tests/Acceptance/FrankenPhpWorker/leak.php';
    private const string SEED = 'tests/Acceptance/FrankenPhpWorker/seed.php';

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
        foreach ([self::HARNESS, self::PROBE, self::LEAK, self::SEED, self::PIN] as $relative) {
            self::assertFileExists($this->repoRoot . '/' . $relative, $relative);
        }
        self::assertTrue(is_executable($this->repoRoot . '/' . self::HARNESS), self::HARNESS . ' must be executable.');

        $harness = (string) file_get_contents($this->repoRoot . '/' . self::HARNESS);
        self::assertStringContainsString('FRANKENPHP_BINARY', $harness);
        self::assertStringContainsString('sha256sum', $harness);
        self::assertStringContainsString('public/index.php', $harness);
        self::assertStringContainsString('php-server', $harness);
        self::assertStringContainsString('Missing binary is not skippable', $harness);
        self::assertStringNotContainsString('get.frankenphp.dev', $harness);
        self::assertDoesNotMatchRegularExpression('/^[^#]*\\bskip\\b/mi', $harness);

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
        self::assertStringContainsString('function_exists(\'frankenphp_handle_request\')', $front);
        self::assertStringContainsString('if ($handled > 0)', $front);
        self::assertStringContainsString('$handler();', $front);
        self::assertStringContainsString('new HttpKernel($projectRoot)', $front);
        self::assertStringContainsString('A fresh kernel is built per request', $front);
        self::assertStringContainsString('WAASEYAA_FRANKENPHP_ACCEPTANCE_PROBE', $front);
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
        $process = proc_open(
            ['bash', $harness],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repoRoot,
            $env,
        );
        self::assertIsResource($process);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame(1, $status);
        self::assertIsString($stderr);
        self::assertStringContainsString('FRANKENPHP_BINARY', $stderr);
        self::assertStringNotContainsString('skipped', strtolower($stderr));
    }
}
