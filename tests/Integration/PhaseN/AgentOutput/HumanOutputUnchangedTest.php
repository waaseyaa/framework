<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentOutput;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Asserts that `bin/check-package-layers`'s default (human) output is
 * unchanged by the M4 WP04 `--output=json` wiring. The agent-output
 * mode is opt-in (flag or env var); the default mode must emit the
 * same pass/fail human line the rest of the framework's CI expects.
 *
 * Per FR-C-002 (agent-output is additive, not replace), human consumers
 * should not have to learn a new output format to use the framework's
 * gates locally.
 *
 * @api
 */
#[CoversNothing]
final class HumanOutputUnchangedTest extends TestCase
{
    #[Test]
    public function defaultModeEmitsTheCanonicalHumanPassLineAndExitsZero(): void
    {
        $result = $this->runCheckScript([], []);

        self::assertSame(0, $result['exit_code'], 'check-package-layers should be green on main; stderr: ' . $result['stderr']);
        self::assertSame(
            'OK — package layer constraints (composer.json + PHP file-level) satisfied.',
            trim($result['stdout']),
            'Default human output line must be unchanged.',
        );
    }

    #[Test]
    public function noEnvelopeJsonAppearsInHumanModeOutput(): void
    {
        $result = $this->runCheckScript([], []);

        self::assertStringNotContainsString('"tool":', $result['stdout']);
        self::assertStringNotContainsString('"result":', $result['stdout']);
        self::assertStringNotContainsString('"violations":', $result['stdout']);
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
