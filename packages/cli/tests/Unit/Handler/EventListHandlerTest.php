<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\EventListHandler;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(EventListHandler::class)]
final class EventListHandlerTest extends TestCase
{
    private function makeDefinition(): HandlerCommand
    {
        return new HandlerCommand(
            name: 'event:list',
            description: 'List all registered events and listeners',
            handler: [EventListHandler::class, 'execute'],
        );
    }

    private function makeContainer(EventDispatcher $dispatcher): ContainerInterface
    {
        return new class ($dispatcher) implements ContainerInterface {
            public function __construct(private readonly EventDispatcher $dispatcher) {}

            public function get(string $id): mixed
            {
                if ($id === EventListHandler::class) {
                    return new EventListHandler($this->dispatcher);
                }

                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return $id === EventListHandler::class;
            }
        };
    }

    #[Test]
    public function showsMessageWhenNoEvents(): void
    {
        $dispatcher = new EventDispatcher();
        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($dispatcher));
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('No events registered.', $tester->getStdout());
    }

    #[Test]
    public function listsRegisteredListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('some.event', static function (): void {});

        $tester = CliTester::for($this->makeDefinition(), $this->makeContainer($dispatcher));
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('some.event', $tester->getStdout());
    }
}
