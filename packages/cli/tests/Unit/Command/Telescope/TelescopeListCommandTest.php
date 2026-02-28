<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Telescope;

use Aurora\CLI\Command\Telescope\TelescopeListCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(TelescopeListCommand::class)]
final class TelescopeListCommandTest extends TestCase
{
    #[Test]
    public function it_shows_not_enabled_when_store_is_null(): void
    {
        $tester = $this->createTester(null);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Telescope is not enabled', $output);
    }

    #[Test]
    public function it_shows_entries_from_store(): void
    {
        $store = new class {
            /** @return array<int, array{time: string, type: string, summary: string, duration?: float}> */
            public function getEntries(?string $type, int $limit, ?int $slowThreshold): array
            {
                return [
                    ['time' => '2026-01-01 12:00:00', 'type' => 'query', 'summary' => 'SELECT * FROM nodes', 'duration' => 12.5],
                    ['time' => '2026-01-01 12:00:01', 'type' => 'event', 'summary' => 'entity.saved', 'duration' => 1.2],
                ];
            }
        };

        $tester = $this->createTester($store);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('SELECT * FROM nodes', $output);
        $this->assertStringContainsString('entity.saved', $output);
        $this->assertStringContainsString('12.5ms', $output);
    }

    #[Test]
    public function it_shows_no_entries_message(): void
    {
        $store = new class {
            /** @return array<int, array{time: string, type: string, summary: string, duration?: float}> */
            public function getEntries(?string $type, int $limit, ?int $slowThreshold): array
            {
                return [];
            }
        };

        $tester = $this->createTester($store);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('No telescope entries found.', $output);
    }

    private function createTester(?object $store): CommandTester
    {
        $app = new Application();
        $app->add(new TelescopeListCommand($store));
        $command = $app->find('telescope');

        return new CommandTester($command);
    }
}
