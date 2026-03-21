<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Event;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Tests\Unit\TestEntity;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @covers \Waaseyaa\Entity\Event\EntityEvent
 * @covers \Waaseyaa\Entity\Event\EntityEvents
 */
class EntityEventTest extends TestCase
{
    public function testEntityEventExtendsSymfonyEvent(): void
    {
        $entity = new TestEntity(['id' => 1]);
        $event = new EntityEvent($entity);

        $this->assertInstanceOf(Event::class, $event);
    }

    public function testEntityEventHoldsEntity(): void
    {
        $entity = new TestEntity(['id' => 1, 'label' => 'Test']);
        $event = new EntityEvent($entity);

        $this->assertSame($entity, $event->entity);
        $this->assertInstanceOf(EntityInterface::class, $event->entity);
    }

    public function testEntityEventOriginalEntityIsNullByDefault(): void
    {
        $entity = new TestEntity(['id' => 1]);
        $event = new EntityEvent($entity);

        $this->assertNull($event->originalEntity);
    }

    public function testEntityEventCarriesOriginalEntity(): void
    {
        $original = new TestEntity(['id' => 1, 'label' => 'Old Title']);
        $updated = new TestEntity(['id' => 1, 'label' => 'New Title']);

        $event = new EntityEvent($updated, $original);

        $this->assertSame($updated, $event->entity);
        $this->assertSame($original, $event->originalEntity);
    }

    public function testEntityEventsEnumValues(): void
    {
        $this->assertSame('waaseyaa.entity.pre_save', EntityEvents::PRE_SAVE->value);
        $this->assertSame('waaseyaa.entity.post_save', EntityEvents::POST_SAVE->value);
        $this->assertSame('waaseyaa.entity.pre_delete', EntityEvents::PRE_DELETE->value);
        $this->assertSame('waaseyaa.entity.post_delete', EntityEvents::POST_DELETE->value);
        $this->assertSame('waaseyaa.entity.post_load', EntityEvents::POST_LOAD->value);
        $this->assertSame('waaseyaa.entity.pre_create', EntityEvents::PRE_CREATE->value);
    }

    public function testEntityEventsEnumCases(): void
    {
        $cases = EntityEvents::cases();

        $this->assertCount(8, $cases);
    }

    public function testEntityEventPropertiesAreReadonly(): void
    {
        $reflection = new \ReflectionClass(EntityEvent::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        foreach ($params as $param) {
            $this->assertTrue(
                $param->isPromoted(),
                "Parameter {$param->getName()} should be a promoted property",
            );
        }

        $entityProp = $reflection->getProperty('entity');
        $this->assertTrue($entityProp->isReadOnly());

        $originalProp = $reflection->getProperty('originalEntity');
        $this->assertTrue($originalProp->isReadOnly());
    }
}
