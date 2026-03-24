<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\DefaultEntityEventFactory;
use Waaseyaa\Entity\Event\EntityEvent;
#[CoversClass(DefaultEntityEventFactory::class)]
final class DefaultEntityEventFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsEntityEvent(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $factory = new DefaultEntityEventFactory();

        $event = $factory->create($entity);

        $this->assertInstanceOf(EntityEvent::class, $event);
        $this->assertSame($entity, $event->entity);
        $this->assertNull($event->originalEntity);
    }

    #[Test]
    public function createPassesOriginalEntity(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $original = $this->createStub(EntityInterface::class);
        $factory = new DefaultEntityEventFactory();

        $event = $factory->create($entity, $original);

        $this->assertSame($entity, $event->entity);
        $this->assertSame($original, $event->originalEntity);
    }
}
