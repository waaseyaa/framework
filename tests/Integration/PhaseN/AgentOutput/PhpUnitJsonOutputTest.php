<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentOutput;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end round-trip test for the M4 WP04D PHPUnit extension.
 *
 * Shells out to `vendor/bin/phpunit` against a small known-passing test
 * file (one of the already-shipped `AgentDetectorTest` files) with
 * `WAASEYAA_OUTPUT=json` set. Asserts the extension emits a
 * well-formed `PhpUnitFormatter` envelope as the last line on stdout.
 * Also asserts the inverse: without the env var, no envelope appears.
 *
 * @api
 */
#[CoversNothing]
final class PhpUnitJsonOutputTest extends TestCase
{
    private const string TARGET_TEST_FILE = 'packages/agent-output/tests/Unit/AgentDetectorTest.php';

    #[Test]
    public function envVarTriggersEnvelopeEmission(): void
    {
        $result = $this->runPhpunit([self::TARGET_TEST_FILE], ['WAASEYAA_OUTPUT' => 'json']);

        // We deliberately don't assert exit code here: PHPUnit's
        // failOnRisky/failOnWarning options promote the "No code coverage
        // driver available" runtime warning to a non-zero exit on local
        // dev machines (CI installs xdebug). The envelope content is the
        // real contract — assert against it.
        $envelope = $this->extractEnvelopeFromStdout($result['stdout']);
        self::assertSame('phpunit', $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        self::assertGreaterThan(0, $envelope['passed']);
        self::assertSame(0, $envelope['failed']);
        self::assertSame([], $envelope['failures']);
        // Per the PhpUnitFormatter contract, the canonical keys are always present.
        foreach (['tool', 'result', 'suite', 'passed', 'failed', 'skipped', 'duration_ms', 'failures'] as $key) {
            self::assertArrayHasKey($key, $envelope);
        }
    }

    #[Test]
    public function humanModeDoesNotEmitEnvelope(): void
    {
        $result = $this->runPhpunit([self::TARGET_TEST_FILE], []);

        self::assertStringNotContainsString('"tool":"phpunit"', $result['stdout']);
        // Sanity: human mode still shows the canonical PHPUnit footer.
        self::assertStringContainsString('Tests:', $result['stdout']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEnvelopeFromStdout(string $stdout): array
    {
        // The envelope is one JSON line emitted by ExecutionFinishedSubscriber;
        // PHPUnit's own progress + footer interleave before/after it.
        $needle = '"tool":"phpunit"';
        $position = strpos($stdout, $needle);
        self::assertNotFalse($position, 'No PHPUnit agent-output envelope found in stdout. Got: ' . $stdout);

        // Walk backwards to the opening brace; forwards to the line break.
        $openBrace = strrpos(substr($stdout, 0, $position), '{');
        self::assertNotFalse($openBrace);
        $newline = strpos($stdout, "\n", $position);
        $jsonSlice = $newline === false
            ? substr($stdout, $openBrace)
            : substr($stdout, $openBrace, $newline - $openBrace);

        $envelope = json_decode($jsonSlice, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);

        return $envelope;
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runPhpunit(array $args, array $env): array
    {
        $projectRoot = dirname(__DIR__, 4);
        $phpunit = $projectRoot . '/vendor/bin/phpunit';
        self::assertFileExists($phpunit);

        $command = array_merge([$phpunit], $args);
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
