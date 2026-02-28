<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Make;

use Aurora\CLI\Command\Make\MakeListenerCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakeListenerCommand::class)]
final class MakeListenerCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_listener_with_default_event(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'NotifyOnPublish']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('class NotifyOnPublish', $output);
        $this->assertStringContainsString('public function __invoke(object $event): void', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_generates_a_listener_with_custom_event(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'name' => 'NotifyOnPublish',
            '--event' => 'Aurora\\Entity\\Event\\EntityEvent',
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('use Aurora\\Entity\\Event\\EntityEvent;', $output);
        $this->assertStringContainsString('public function __invoke(EntityEvent $event): void', $output);
    }

    #[Test]
    public function it_shows_async_hint_when_flag_is_set(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'name' => 'NotifyOnPublish',
            '--async' => true,
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('async dispatch', $output);
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->add(new MakeListenerCommand());
        $command = $app->find('make:listener');

        return new CommandTester($command);
    }
}
