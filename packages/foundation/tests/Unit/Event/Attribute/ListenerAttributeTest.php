<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Event\Attribute;

use Aurora\Foundation\Event\Attribute\Async;
use Aurora\Foundation\Event\Attribute\Broadcast;
use Aurora\Foundation\Event\Attribute\Listener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Listener::class)]
#[CoversClass(Async::class)]
#[CoversClass(Broadcast::class)]
final class ListenerAttributeTest extends TestCase
{
    #[Test]
    public function listener_attribute_discoverable_on_class(): void
    {
        $ref = new \ReflectionClass(SampleListener::class);
        $attrs = $ref->getAttributes(Listener::class);

        $this->assertCount(1, $attrs);
    }

    #[Test]
    public function async_attribute_discoverable_on_method(): void
    {
        $ref = new \ReflectionMethod(SampleListener::class, '__invoke');
        $attrs = $ref->getAttributes(Async::class);

        $this->assertCount(1, $attrs);
    }

    #[Test]
    public function broadcast_attribute_carries_channel(): void
    {
        $ref = new \ReflectionClass(SampleBroadcastListener::class);
        $attrs = $ref->getAttributes(Broadcast::class);
        $broadcast = $attrs[0]->newInstance();

        $this->assertSame('admin.{aggregateType}', $broadcast->channel);
    }

    #[Test]
    public function listener_has_optional_priority(): void
    {
        $ref = new \ReflectionClass(SampleListener::class);
        $listener = $ref->getAttributes(Listener::class)[0]->newInstance();

        $this->assertSame(0, $listener->priority);
    }
}

#[Listener]
final class SampleListener
{
    #[Async]
    public function __invoke(): void {}
}

#[Listener]
#[Broadcast(channel: 'admin.{aggregateType}')]
final class SampleBroadcastListener
{
    public function __invoke(): array { return []; }
}
