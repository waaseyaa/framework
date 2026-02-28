<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Telescope;

use Aurora\CLI\Command\Telescope\TelescopeClearCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(TelescopeClearCommand::class)]
final class TelescopeClearCommandTest extends TestCase
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
    public function it_clears_all_entries(): void
    {
        $store = new class {
            public bool $cleared = false;

            public function clear(): void
            {
                $this->cleared = true;
            }
        };

        $tester = $this->createTester($store);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('All telescope entries cleared.', $output);
        $this->assertTrue($store->cleared);
    }

    #[Test]
    public function it_clears_entries_by_type(): void
    {
        $store = new class {
            public ?string $clearedType = null;

            public function clearByType(string $type): void
            {
                $this->clearedType = $type;
            }
        };

        $tester = $this->createTester($store);
        $tester->execute(['--type' => 'query']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('entries of type "query" cleared', $output);
        $this->assertSame('query', $store->clearedType);
    }

    private function createTester(?object $store): CommandTester
    {
        $app = new Application();
        $app->add(new TelescopeClearCommand($store));
        $command = $app->find('telescope:clear');

        return new CommandTester($command);
    }
}
