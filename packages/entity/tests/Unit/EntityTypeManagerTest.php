<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Exception\EntityTypeRegistrationCollisionException;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \Waaseyaa\Entity\EntityTypeManager
 */
class EntityTypeManagerTest extends TestCase
{
    private EntityTypeManager $manager;
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->manager = new EntityTypeManager($this->eventDispatcher);
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(EntityTypeManagerInterface::class, $this->manager);
    }

    public function testRegisterAndGetDefinition(): void
    {
        $type = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestEntity::class,
        );

        $this->manager->registerEntityType($type);

        $retrieved = $this->manager->getDefinition('node');
        $this->assertSame($type, $retrieved);
    }

    public function testGetDefinitions(): void
    {
        $nodeType = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $userType = new EntityType(id: 'user', label: 'User', class: TestEntity::class);

        $this->manager->registerEntityType($nodeType);
        $this->manager->registerEntityType($userType);

        $definitions = $this->manager->getDefinitions();

        $this->assertCount(2, $definitions);
        $this->assertArrayHasKey('node', $definitions);
        $this->assertArrayHasKey('user', $definitions);
        $this->assertSame($nodeType, $definitions['node']);
        $this->assertSame($userType, $definitions['user']);
    }

    public function testHasDefinition(): void
    {
        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $this->manager->registerEntityType($type);

        $this->assertTrue($this->manager->hasDefinition('node'));
        $this->assertFalse($this->manager->hasDefinition('nonexistent'));
    }

    public function testGetDefinitionThrowsForUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity type "unknown" is not registered.');

        $this->manager->getDefinition('unknown');
    }

    public function testRegisterDuplicateThrowsCollisionExceptionForSameClassWithRegistrants(): void
    {
        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $this->manager->registerEntityType($type, ExistingRegistrant::class);

        $this->expectException(EntityTypeRegistrationCollisionException::class);
        $this->expectExceptionMessage('[ENTITY_TYPE_DUPLICATE]');
        $this->expectExceptionMessage(ExistingRegistrant::class);
        $this->expectExceptionMessage(IncomingRegistrant::class);
        $this->expectExceptionMessage(TestEntity::class);

        $duplicate = new EntityType(id: 'node', label: 'Content v2', class: TestEntity::class);
        $this->manager->registerEntityType($duplicate, IncomingRegistrant::class);
    }

    public function testRegisterDuplicateThrowsShadowCollisionForDifferentClass(): void
    {
        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $this->manager->registerEntityType($type, ExistingRegistrant::class);

        $this->expectException(EntityTypeRegistrationCollisionException::class);
        $this->expectExceptionMessage('[ENTITY_TYPE_SHADOW_COLLISION]');
        $this->expectExceptionMessage('node');
        $this->expectExceptionMessage(ExistingRegistrant::class);
        $this->expectExceptionMessage(IncomingRegistrant::class);
        $this->expectExceptionMessage(TestEntity::class);
        $this->expectExceptionMessage(ShadowEntity::class);

        $duplicate = new EntityType(id: 'node', label: 'Content v2', class: ShadowEntity::class);
        $this->manager->registerEntityType($duplicate, IncomingRegistrant::class);
    }

    public function testRegisterDuplicateFallsBackToUnknownRegistrantWhenProvenanceMissing(): void
    {
        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $this->manager->registerEntityType($type);

        $this->expectException(EntityTypeRegistrationCollisionException::class);
        $this->expectExceptionMessage('an unknown registrant');

        $duplicate = new EntityType(id: 'node', label: 'Content v2', class: TestEntity::class);
        $this->manager->registerEntityType($duplicate);
    }

    public function testGetStorageWithFactory(): void
    {
        $mockStorage = $this->createStub(EntityStorageInterface::class);

        $manager = new EntityTypeManager(
            $this->eventDispatcher,
            function ($definition) use ($mockStorage) {
                return $mockStorage;
            },
        );

        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $manager->registerEntityType($type);

        $storage = $manager->getStorage('node');

        $this->assertSame($mockStorage, $storage);
    }

    public function testGetStorageCachesInstances(): void
    {
        $callCount = 0;
        $mockStorage = $this->createStub(EntityStorageInterface::class);

        $manager = new EntityTypeManager(
            $this->eventDispatcher,
            function ($definition) use ($mockStorage, &$callCount) {
                $callCount++;
                return $mockStorage;
            },
        );

        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $manager->registerEntityType($type);

        $storage1 = $manager->getStorage('node');
        $storage2 = $manager->getStorage('node');

        $this->assertSame($storage1, $storage2);
        $this->assertSame(1, $callCount, 'Factory should only be called once per entity type');
    }

    public function testGetStorageThrowsWhenNoStorageConfigured(): void
    {
        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $this->manager->registerEntityType($type);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No storage class configured');

        $this->manager->getStorage('node');
    }

    public function testGetStorageThrowsForUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity type "unknown" is not registered.');

        $this->manager->getStorage('unknown');
    }

    public function testGetStorageThrowsWhenFactoryReturnsInvalidType(): void
    {
        $manager = new EntityTypeManager(
            $this->eventDispatcher,
            function ($definition) {
                return new \stdClass();
            },
        );

        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $manager->registerEntityType($type);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must implement');

        $manager->getStorage('node');
    }

    public function testGetEventDispatcher(): void
    {
        $this->assertSame($this->eventDispatcher, $this->manager->getEventDispatcher());
    }

    public function testGetRepositoryThrowsWhenNoFactoryConfigured(): void
    {
        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $this->manager->registerEntityType($type);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No repository factory configured');

        $this->manager->getRepository('node');
    }

    public function testGetRepositoryUsesFactoryAndCaches(): void
    {
        $mockRepo = $this->createStub(EntityRepositoryInterface::class);
        $callCount = 0;

        $manager = new EntityTypeManager(
            $this->eventDispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($mockRepo, &$callCount) {
                $callCount++;
                $this->assertSame('node', $entityTypeId);
                $this->assertSame('node', $definition->id());

                return $mockRepo;
            },
        );

        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $manager->registerEntityType($type);

        $this->assertSame($mockRepo, $manager->getRepository('node'));
        $this->assertSame($mockRepo, $manager->getRepository('node'));
        $this->assertSame(1, $callCount);
    }

    public function testGetRepositoryThrowsWhenFactoryReturnsWrongType(): void
    {
        $manager = new EntityTypeManager(
            $this->eventDispatcher,
            null,
            static fn (): object => new \stdClass(),
        );

        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $manager->registerEntityType($type);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return an instance');

        $manager->getRepository('node');
    }

    public function testGetDefinitionsReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->manager->getDefinitions());
    }

    // -----------------------------------------------------------------------
    // Namespace reservation (#206)
    // -----------------------------------------------------------------------

    public function testRegisterEntityTypeWithCoreNamespaceThrows(): void
    {
        $type = new EntityType(id: 'core.custom', label: 'Custom', class: TestEntity::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('[NAMESPACE_RESERVED]');

        $this->manager->registerEntityType($type);
    }

    public function testRegisterEntityTypeWithCoreNamespaceErrorIncludesTypeId(): void
    {
        $type = new EntityType(id: 'core.custom', label: 'Custom', class: TestEntity::class);

        try {
            $this->manager->registerEntityType($type);
            $this->fail('Expected DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('core.custom', $e->getMessage());
        }
    }

    public function testRegisterEntityTypeWithoutCoreNamespaceSucceeds(): void
    {
        $type = new EntityType(id: 'note', label: 'Note', class: TestEntity::class);
        $this->manager->registerEntityType($type);

        $this->assertTrue($this->manager->hasDefinition('note'));
    }

    public function testRegisterCoreEntityTypeWithCoreNamespaceSucceeds(): void
    {
        $type = new EntityType(id: 'core.note', label: 'Note', class: TestEntity::class);
        $this->manager->registerCoreEntityType($type);

        $this->assertTrue($this->manager->hasDefinition('core.note'));
    }

    public function testRegisterCoreEntityTypeWithoutCoreNamespaceAlsoSucceeds(): void
    {
        $type = new EntityType(id: 'node', label: 'Content', class: TestEntity::class);
        $this->manager->registerCoreEntityType($type);

        $this->assertTrue($this->manager->hasDefinition('node'));
    }

    public function testRegisterCoreEntityTypeRejectsDuplicates(): void
    {
        $type = new EntityType(id: 'core.note', label: 'Note', class: TestEntity::class);
        $this->manager->registerCoreEntityType($type);

        $this->expectException(EntityTypeRegistrationCollisionException::class);

        $duplicate = new EntityType(id: 'core.note', label: 'Note v2', class: TestEntity::class);
        $this->manager->registerCoreEntityType($duplicate);
    }
}

final class ExistingRegistrant {}

final class IncomingRegistrant {}

final class ShadowEntity {}
