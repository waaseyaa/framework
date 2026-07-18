<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Hydration;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Hydration\EntityInstantiator;
use Waaseyaa\EntityStorage\Tests\Fixtures\HydratableFromStorageTestEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\NonHydratableEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityInstantiator::class)]
final class EntityInstantiatorTest extends TestCase
{
    #[Test]
    public function instantiateUsesFromStorageWhenImplemented(): void
    {
        $entityType = new EntityType(
            id: 'hydratable_test_entity',
            label: 'Hydratable Test',
            class: HydratableFromStorageTestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );

        $instantiator = new EntityInstantiator($entityType);
        $entity = $instantiator->instantiate(HydratableFromStorageTestEntity::class, [
            'id' => '1',
            'label' => 'X',
            'bundle' => 'b',
            'langcode' => 'en',
        ]);

        $this->assertInstanceOf(HydratableFromStorageTestEntity::class, $entity);
        $this->assertTrue($entity->get('_rehydrated_via_storage'));
        $this->assertSame('hydratable_test_entity', $entity->get('_context_type'));
        $structure = $entity->entityStructure();
        $this->assertSame('hydratable_test_entity', $structure->entityTypeId);
        $this->assertSame('b', $structure->bundleId);
        $this->assertSame('1', $structure->id);
        $this->assertSame('en', $structure->activeLanguageId);
        $this->assertSame('en', $structure->defaultLanguageId);
        $this->assertSame(['en'], $structure->knownTranslationIds);
        $this->assertTrue($structure->hasField('id'));
        $this->assertTrue($structure->hasField('bundle'));
        $this->assertTrue($structure->hasField('label'));
    }

    #[Test]
    public function instantiateThrowsWhenEntityIsNotHydratableFromStorage(): void
    {
        $entityType = new EntityType(
            id: 'test_entity',
            label: 'Test',
            class: NonHydratableEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );

        $instantiator = new EntityInstantiator($entityType);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must implement');
        $instantiator->instantiate(NonHydratableEntity::class, [
            'id' => '1',
            'label' => 'Legacy',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);
    }

    #[Test]
    public function instantiateThrowsWhenClassIsNotEntityInterface(): void
    {
        $entityType = new EntityType(
            id: 'x',
            label: 'X',
            class: TestStorageEntity::class,
        );

        $instantiator = new EntityInstantiator($entityType);

        $this->expectException(\InvalidArgumentException::class);
        $instantiator->instantiate(\stdClass::class, []);
    }

}
