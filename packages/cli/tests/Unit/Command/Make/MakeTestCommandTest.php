<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Make;

use Aurora\CLI\Command\Make\MakeTestCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakeTestCommand::class)]
final class MakeTestCommandTest extends TestCase
{
    #[Test]
    public function it_generates_an_integration_test_by_default(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'NodeRepositoryTest']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('class NodeRepositoryTest extends TestCase', $output);
        $this->assertStringContainsString('namespace App\\Tests\\Integration;', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_generates_a_unit_test_with_flag(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'NodeRepositoryTest', '--unit' => true]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('namespace App\\Tests\\Unit;', $output);
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->add(new MakeTestCommand());
        $command = $app->find('make:test');

        return new CommandTester($command);
    }
}
