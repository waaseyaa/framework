<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentOutput;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end round-trip test for the three additional `bin/check-*`
 * scripts wired with `--output=json` in M4 WP04B. (The first script,
 * `bin/check-package-layers`, is covered separately by
 * `PackageLayersJsonOutputTest` since it shipped in M4 WP04 part 1.)
 *
 * Each script must emit a single well-formed NDJSON envelope on stdout
 * with `tool` matching the script name, `result` ∈ {pass, fail}, and
 * the per-tool structured-detail fields documented on the matching
 * formatter class.
 *
 * @api
 */
#[CoversNothing]
final class BinChecksJsonOutputTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function scriptsProvider(): iterable
    {
        yield 'check-dead-code' => [
            'bin/check-dead-code',
            ['tool', 'result', 'baseline_count', 'new_count', 'findings'],
            ['check-dead-code'],
        ];
        yield 'check-composer-policy' => [
            'bin/check-composer-policy',
            ['tool', 'result', 'files_scanned', 'failures'],
            ['check-composer-policy'],
        ];
        yield 'check-getquery-bindings' => [
            'bin/check-getquery-bindings',
            ['tool', 'result', 'baseline_count', 'new_count', 'offenders'],
            ['check-getquery-bindings'],
        ];
    }

    /**
     * @param list<string> $expectedKeys
     * @param list<string> $expectedToolName Single-element list for sane diff output on mismatch.
     */
    #[Test]
    #[DataProvider('scriptsProvider')]
    public function flagJsonModeEmitsAValidEnvelopeAndExitsZeroOnCleanRepo(string $binPath, array $expectedKeys, array $expectedToolName): void
    {
        $result = $this->runScript($binPath, ['--output=json'], []);

        self::assertSame(0, $result['exit_code'], sprintf('%s should be green on main; stderr: %s', $binPath, $result['stderr']));
        $envelope = $this->assertSingleEnvelopeOnStdout($result['stdout']);

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $envelope, sprintf('%s envelope missing key %s', $binPath, $key));
        }
        self::assertSame($expectedToolName[0], $envelope['tool']);
        self::assertSame('pass', $envelope['result'], sprintf('%s should be passing on main; envelope: %s', $binPath, json_encode($envelope)));
    }

    /**
     * @param list<string> $expectedKeys
     * @param list<string> $expectedToolName
     */
    #[Test]
    #[DataProvider('scriptsProvider')]
    public function envVarTriggersJsonModeWithoutTheFlag(string $binPath, array $expectedKeys, array $expectedToolName): void
    {
        $result = $this->runScript($binPath, [], ['WAASEYAA_OUTPUT' => 'json']);

        self::assertSame(0, $result['exit_code']);
        $envelope = $this->assertSingleEnvelopeOnStdout($result['stdout']);
        self::assertSame($expectedToolName[0], $envelope['tool']);
        self::assertSame('pass', $envelope['result']);
        // Env-var mode must produce the same key set as flag mode.
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $envelope);
        }
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
    private function runScript(string $binRelativePath, array $args, array $env): array
    {
        $projectRoot = dirname(__DIR__, 4);
        $bin = $projectRoot . '/' . $binRelativePath;
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
