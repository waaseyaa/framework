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
    public function test_default_boundary_is_activated(): void
    {
        $entity = new SerializationBoundaryFixtureEntity(['id' => 1], 'node', ['id' => 'id']);

        $this->expectException(EntitySerializationForbidden::class);
        new EntitySerializationBoundary()->serialize($entity);
    }

    public function test_legacy_dormant_switch_cannot_bypass_the_activated_entity_runtime(): void
    {
        $entity = new SerializationBoundaryFixtureEntity(['id' => 1], 'node', ['id' => 'id']);

        $this->expectException(EntitySerializationForbidden::class);
        new EntitySerializationBoundary(EntitySerializationBoundaryConfig::dormant())->serialize($entity);
    }
}

final class SerializationBoundaryFixtureEntity extends EntityBase {}
