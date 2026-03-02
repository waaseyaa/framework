<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Optimize;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\Optimize\OptimizeCommand;

#[CoversClass(OptimizeCommand::class)]
final class OptimizeCommandTest extends TestCase
{
    #[Test]
    public function skips_missing_sub_commands_gracefully(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->add(new OptimizeCommand());

        $tester = new CommandTester($app->find('optimize'));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Skipping optimize:manifest', $output);
        $this->assertStringContainsString('Skipping optimize:config', $output);
    }

    #[Test]
    public function runs_registered_sub_commands(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->add(new OptimizeCommand());

        // Add a stub sub-command
        $stubManifest = new class extends Command {
            protected static $defaultName = 'optimize:manifest';

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('Manifest compiled.');
                return Command::SUCCESS;
            }
        };
        $stubManifest->setName('optimize:manifest');

        $stubConfig = new class extends Command {
            protected static $defaultName = 'optimize:config';

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('Config compiled.');
                return Command::SUCCESS;
            }
        };
        $stubConfig->setName('optimize:config');

        $app->add($stubManifest);
        $app->add($stubConfig);

        $tester = new CommandTester($app->find('optimize'));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Manifest compiled', $output);
        $this->assertStringContainsString('Config compiled', $output);
        $this->assertStringContainsString('All optimizations complete', $output);
    }

    #[Test]
    public function stops_on_sub_command_failure(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->add(new OptimizeCommand());

        $failingManifest = new class extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return Command::FAILURE;
            }
        };
        $failingManifest->setName('optimize:manifest');
        $app->add($failingManifest);

        $tester = new CommandTester($app->find('optimize'));
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('optimize:manifest failed', $tester->getDisplay());
    }

    #[Test]
    public function reports_when_no_commands_registered(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->add(new OptimizeCommand());

        $tester = new CommandTester($app->find('optimize'));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No optimization commands are registered', $tester->getDisplay());
    }
}
