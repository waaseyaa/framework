<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntitySerializationBoundary;
use Waaseyaa\Entity\EntitySerializationBoundaryConfig;
use Waaseyaa\Entity\Exception\EntitySerializationForbidden;

final class EntitySerializationBoundaryTest extends TestCase
{
    public function test_dormant_boundary_preserves_legacy_serialization_but_activation_rejects(): void
    {
        $entity = new SerializationBoundaryFixtureEntity(['id' => 1], 'node', ['id' => 'id']);

        self::assertNotSame('', new EntitySerializationBoundary(EntitySerializationBoundaryConfig::dormant())->serialize($entity));

        $this->expectException(EntitySerializationForbidden::class);
        new EntitySerializationBoundary(EntitySerializationBoundaryConfig::enforced())->serialize($entity);
    }

    public function test_dormant_compatibility_entity_remains_readable_after_serialization_round_trip(): void
    {
        $entity = new SerializationBoundaryFixtureEntity(['id' => 1, 'title' => 'Legacy'], 'node', ['id' => 'id']);

        $copy = unserialize(serialize($entity));

        self::assertInstanceOf(SerializationBoundaryFixtureEntity::class, $copy);
        self::assertSame('Legacy', $copy->get('title'));
    }
}

final class SerializationBoundaryFixtureEntity extends EntityBase {}
