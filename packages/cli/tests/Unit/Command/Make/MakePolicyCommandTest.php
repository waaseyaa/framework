<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Make;

use Aurora\CLI\Command\Make\MakePolicyCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakePolicyCommand::class)]
final class MakePolicyCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_policy_class(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'ContentPolicy']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('class ContentPolicy implements AccessPolicyInterface', $output);
        $this->assertStringContainsString('use Aurora\\Access\\AccessPolicyInterface;', $output);
        $this->assertStringContainsString('public function view(', $output);
        $this->assertStringContainsString('public function create(', $output);
        $this->assertStringContainsString('public function update(', $output);
        $this->assertStringContainsString('public function delete(', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->add(new MakePolicyCommand());
        $command = $app->find('make:policy');

        return new CommandTester($command);
    }
}
