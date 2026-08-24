<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class RetiredAgentOutputModeTest extends TestCase
{
    #[Test]
    public function removed_agent_output_json_modes_fail_truthfully_without_loading_ghost_classes(): void
    {
        $root = dirname(__DIR__, 2);
        $commands = [
            [PHP_BINARY, $root . '/bin/check-composer-policy', '--output=json'],
            [PHP_BINARY, $root . '/bin/check-getquery-bindings', '--output=json'],
            ['bash', $root . '/bin/check-phpstan', '--output=json'],
            ['bash', $root . '/tools/drift-detector.sh', '--output=json'],
        ];

        foreach ($commands as $command) {
            [$exit, $output] = $this->runCommand($command, $root);
            self::assertSame(2, $exit, sprintf("%s must reject its retired JSON mode explicitly.\n%s", $command[1], $output));
            self::assertStringContainsString('no longer supported', $output);
            self::assertStringNotContainsString('AgentOutput', $output);
            self::assertStringNotContainsString('Class not found', $output);
        }
    }

    /** @param list<string> $command @return array{int, string} */
    private function runCommand(array $command, string $cwd): array
    {
        // proc_open drained stdout to EOF before touching stderr, so a child
        // filling the ~64KB stderr buffer wedged both sides (#2491). No env
        // argument was passed, so the child inherited the parent environment:
        // null, never []. timeout null because this was never time-bounded and
        // Symfony's constructor otherwise imposes 60 seconds.
        $process = new Process($command, $cwd, null, null, null);
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }
}
