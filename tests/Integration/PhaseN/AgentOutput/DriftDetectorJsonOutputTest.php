<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentOutput;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end round-trip test for `tools/drift-detector.sh --output=json`.
 *
 * Shells out to the production script in both JSON-flag and JSON-env
 * modes. Asserts the script emits a single NDJSON envelope on stdout
 * matching the `DriftDetectorFormatter` contract. The detector is
 * green on `main` (no specs affected by recent commits), so `result`
 * is `pass` and the `stale` list is empty.
 *
 * @api
 */
#[CoversNothing]
final class DriftDetectorJsonOutputTest extends TestCase
{
    #[Test]
    public function flagJsonModeEmitsAValidEnvelopeAndExitsZeroOnCleanRepo(): void
    {
        $result = $this->runScript(['5', '--output=json'], []);

        self::assertSame(0, $result['exit_code'], 'drift-detector should be green on main; stderr: ' . $result['stderr']);
        $envelope = $this->assertSingleEnvelopeOnStdout($result['stdout']);
        self::assertSame('drift-detector', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertSame(0, $envelope['stale_count']);
        self::assertSame([], $envelope['stale']);
        self::assertArrayHasKey('ok_specs', $envelope);
        self::assertIsInt($envelope['ok_specs']);
    }

    #[Test]
    public function envVarTriggersJsonModeWithoutTheFlag(): void
    {
        $result = $this->runScript(['5'], ['WAASEYAA_OUTPUT' => 'json']);

        self::assertSame(0, $result['exit_code']);
        $envelope = $this->assertSingleEnvelopeOnStdout($result['stdout']);
        self::assertSame('drift-detector', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
    }

    #[Test]
    public function humanModeDefaultUnchangedAndExits0OnCleanRepo(): void
    {
        $result = $this->runScript(['5'], []);

        self::assertSame(0, $result['exit_code']);
        // Default human output preserves the canonical preface and resolution.
        self::assertStringContainsString('=== Drift Detector ===', $result['stdout']);
        self::assertStringContainsString('Checking last 5 commits', $result['stdout']);
        self::assertStringNotContainsString('"tool":', $result['stdout']);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertSingleEnvelopeOnStdout(string $stdout): array
    {
        $trimmed = rtrim($stdout, "\n");
        self::assertNotSame('', $trimmed, 'JSON mode must emit one envelope on stdout.');
        self::assertStringNotContainsString("\n", $trimmed, 'Multi-line NDJSON not expected for a single invocation; got: ' . $trimmed);

        $envelope = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);

        return $envelope;
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runScript(array $args, array $env): array
    {
        $projectRoot = dirname(__DIR__, 4);
        $bin = $projectRoot . '/tools/drift-detector.sh';
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
