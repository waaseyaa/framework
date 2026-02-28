<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\EventListCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(EventListCommand::class)]
final class EventListCommandTest extends TestCase
{
    #[Test]
    public function it_lists_events_and_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $listener = new class {
            public function onSave(): void {}
        };
        $dispatcher->addListener('entity.saved', [$listener, 'onSave'], 10);

        $tester = $this->createTester($dispatcher);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('entity.saved', $output);
        $this->assertStringContainsString('::onSave', $output);
        $this->assertStringContainsString('10', $output);
    }

    #[Test]
    public function it_lists_closure_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('cache.clear', function (): void {});

        $tester = $this->createTester($dispatcher);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('cache.clear', $output);
        $this->assertStringContainsString('Closure', $output);
    }

    #[Test]
    public function it_shows_message_when_no_events(): void
    {
        $dispatcher = new EventDispatcher();

        $tester = $this->createTester($dispatcher);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('No events registered.', $output);
    }

    private function createTester(EventDispatcher $dispatcher): CommandTester
    {
        $app = new Application();
        $app->add(new EventListCommand($dispatcher));
        $command = $app->find('event:list');

        return new CommandTester($command);
    }
}
