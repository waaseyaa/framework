<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Telescope;

use Aurora\CLI\Command\Telescope\TelescopePruneCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(TelescopePruneCommand::class)]
final class TelescopePruneCommandTest extends TestCase
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
    public function it_prunes_with_default_hours(): void
    {
        $store = new class {
            public ?int $prunedHours = null;

            public function prune(int $hours): int
            {
                $this->prunedHours = $hours;

                return 42;
            }
        };

        $tester = $this->createTester($store);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Pruned 42 telescope entries older than 24 hours', $output);
        $this->assertSame(24, $store->prunedHours);
    }

    #[Test]
    public function it_prunes_with_custom_hours(): void
    {
        $store = new class {
            public ?int $prunedHours = null;

            public function prune(int $hours): int
            {
                $this->prunedHours = $hours;

                return 10;
            }
        };

        $tester = $this->createTester($store);
        $tester->execute(['--hours' => '48']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('older than 48 hours', $output);
        $this->assertSame(48, $store->prunedHours);
    }

    private function createTester(?object $store): CommandTester
    {
        $app = new Application();
        $app->add(new TelescopePruneCommand($store));
        $command = $app->find('telescope:prune');

        return new CommandTester($command);
    }
}
