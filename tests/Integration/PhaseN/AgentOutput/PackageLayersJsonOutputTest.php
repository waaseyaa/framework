<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentOutput;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end round-trip test for `bin/check-package-layers --output=json`.
 *
 * Shells out to the production script in both JSON-flag and JSON-env modes
 * and asserts the script emits a single NDJSON envelope on stdout that
 * matches the contract documented in
 * `packages/agent-output/src/Formatter/PackageLayersFormatter.php`. The
 * gate must remain green on `main` for this test to assert `result: pass`.
 *
 * @api
 */
#[CoversNothing]
final class PackageLayersJsonOutputTest extends TestCase
{
    #[Test]
    public function flagJsonModeEmitsAValidEnvelopeAndExitsZeroOnCleanRepo(): void
    {
        $result = $this->runCheckScript(['--output=json'], []);

        self::assertSame(0, $result['exit_code'], 'check-package-layers should be green on main; stderr: ' . $result['stderr']);
        self::assertSame('', trim($result['stderr']), 'JSON mode should not leak human-readable lines to stderr.');

        $envelope = $this->assertSingleEnvelopeOnStdout($result['stdout']);
        self::assertSame('check-package-layers', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertIsInt($envelope['packages_scanned']);
        self::assertGreaterThan(0, $envelope['packages_scanned']);
        self::assertSame([], $envelope['violations']);
    }

    #[Test]
    public function envVarTriggersJsonModeWithoutTheFlag(): void
    {
        $result = $this->runCheckScript([], ['WAASEYAA_OUTPUT' => 'json']);

        self::assertSame(0, $result['exit_code']);
        $envelope = $this->assertSingleEnvelopeOnStdout($result['stdout']);
        self::assertSame('check-package-layers', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
    }

    /**
     * @return array{tool: string, result: string, packages_scanned: int, violations: array<int, mixed>}
     */
    private function assertSingleEnvelopeOnStdout(string $stdout): array
    {
        $trimmed = rtrim($stdout, "\n");
        self::assertNotSame('', $trimmed, 'JSON mode must emit one envelope on stdout.');
        self::assertStringNotContainsString("\n", $trimmed, 'Multi-line NDJSON not expected for a single tool invocation; got: ' . $trimmed);

        $envelope = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        self::assertArrayHasKey('tool', $envelope);
        self::assertArrayHasKey('result', $envelope);
        self::assertArrayHasKey('packages_scanned', $envelope);
        self::assertArrayHasKey('violations', $envelope);

        return $envelope;
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runCheckScript(array $args, array $env): array
    {
        $projectRoot = dirname(__DIR__, 4);
        $bin = $projectRoot . '/bin/check-package-layers';
        self::assertFileExists($bin);

        $command = array_merge([$bin], $args);
        $process = proc_open(
            command: $command,
            descriptor_spec: [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            pipes: $pipes,
            cwd: $projectRoot,
            env_vars: array_merge(getenv(), $env),
        );

        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
