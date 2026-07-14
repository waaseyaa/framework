<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
