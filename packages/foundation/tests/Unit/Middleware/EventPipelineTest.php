<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use Waaseyaa\Foundation\Event\DomainEvent;
use Waaseyaa\Foundation\Middleware\EventHandlerInterface;
use Waaseyaa\Foundation\Middleware\EventMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\EventPipeline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventPipeline::class)]
final class EventPipelineTest extends TestCase
{
    #[Test]
    public function empty_pipeline_delegates_to_final_handler(): void
    {
        $handled = false;
        $pipeline = new EventPipeline();

        $handler = new class($handled) implements EventHandlerInterface {
            public function __construct(private bool &$handled) {}
            public function handle(DomainEvent $event): void
            {
                $this->handled = true;
            }
        };

        $pipeline->handle($this->createEvent(), $handler);
        $this->assertTrue($handled);
    }

    #[Test]
    public function middleware_wraps_in_onion_order(): void
    {
        $log = [];

        $mw1 = new class($log) implements EventMiddlewareInterface {
            public function __construct(private array &$log) {}
            public function process(DomainEvent $event, EventHandlerInterface $next): void
            {
                $this->log[] = 'mw1-before';
                $next->handle($event);
                $this->log[] = 'mw1-after';
            }
        };

        $mw2 = new class($log) implements EventMiddlewareInterface {
            public function __construct(private array &$log) {}
            public function process(DomainEvent $event, EventHandlerInterface $next): void
            {
                $this->log[] = 'mw2-before';
                $next->handle($event);
                $this->log[] = 'mw2-after';
            }
        };

        $handler = new class($log) implements EventHandlerInterface {
            public function __construct(private array &$log) {}
            public function handle(DomainEvent $event): void
            {
                $this->log[] = 'handler';
            }
        };

        $pipeline = new EventPipeline([$mw1, $mw2]);
        $pipeline->handle($this->createEvent(), $handler);

        $this->assertSame(['mw1-before', 'mw2-before', 'handler', 'mw2-after', 'mw1-after'], $log);
    }

    #[Test]
    public function middleware_can_suppress_event(): void
    {
        $reached = false;

        $suppress = new class implements EventMiddlewareInterface {
            public function process(DomainEvent $event, EventHandlerInterface $next): void
            {
                // Don't call next — suppress the event
            }
        };

        $handler = new class($reached) implements EventHandlerInterface {
            public function __construct(private bool &$reached) {}
            public function handle(DomainEvent $event): void
            {
                $this->reached = true;
            }
        };

        $pipeline = new EventPipeline([$suppress]);
        $pipeline->handle($this->createEvent(), $handler);
        $this->assertFalse($reached);
    }

    private function createEvent(): DomainEvent
    {
        return new class('test', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };
    }
}
