<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Hydration\HydrationContext;
use Waaseyaa\EntityStorage\Hydration\EntityInstantiator;
use Waaseyaa\EntityStorage\Tests\Fixtures\HydratableFromStorageTestEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\NonHydratableEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

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

    #[Test]
    public function sealed_v2_hydration_never_exposes_raw_values_to_entity_construction_callbacks(): void
    {
        SealedHydrationFixture::$constructorCalls = 0;
        SealedHydrationFixture::$fromStorageCalls = 0;
        $entityType = new EntityType(
            id: 'sealed_hydration',
            label: 'Sealed hydration',
            class: SealedHydrationFixture::class,
            keys: ['id' => 'id', 'label' => 'name'],
        );
        $layout = new EntityReadLayout(new EntityReadLayoutGeneration(), [
            'id' => FieldReadLevel::Public,
            'name' => FieldReadLevel::Public,
            'mail' => FieldReadLevel::Internal,
        ]);

        $entity = new EntityInstantiator($entityType)->instantiateSealed(
            SealedHydrationFixture::class,
            ['id' => 7, 'name' => '', 'mail' => 'member@example.test'],
            $layout,
        );

        self::assertSame(0, SealedHydrationFixture::$constructorCalls);
        self::assertSame(0, SealedHydrationFixture::$fromStorageCalls);
        self::assertSame(7, $entity->id());
        self::assertSame(['id', 'mail', 'name'], $entity->fieldNames());
        $this->expectException(FieldReadDenied::class);
        $entity->get('mail');
    }

    #[Test]
    public function separately_hydrated_revision_views_have_distinct_guard_identities(): void
    {
        $entityType = new EntityType(
            id: 'sealed_hydration',
            label: 'Sealed hydration',
            class: SealedHydrationFixture::class,
            keys: ['id' => 'id', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        );
        $layout = new EntityReadLayout(new EntityReadLayoutGeneration(), [
            'id' => FieldReadLevel::Public,
            'revision_id' => FieldReadLevel::Public,
            'title' => FieldReadLevel::Protected,
            'mail' => FieldReadLevel::Internal,
        ]);
        $guard = new RevisionViewRecordingGuard();
        $instantiator = new EntityInstantiator($entityType);

        $first = $instantiator->instantiateSealed(SealedHydrationFixture::class, [
            'id' => 7, 'revision_id' => 1, 'title' => 'First', 'mail' => 'member@example.test',
        ], $layout, $guard);
        $second = $instantiator->instantiateSealed(SealedHydrationFixture::class, [
            'id' => 7, 'revision_id' => 2, 'title' => 'Second', 'mail' => 'member@example.test',
        ], $layout, $guard);

        self::assertSame(1, $first->revisionId());
        self::assertSame(2, $second->revisionId());
        self::assertSame('First', $first->get('title'));
        self::assertSame('Second', $second->get('title'));
        self::assertCount(2, array_unique($guard->viewIds));
    }

}

final class RevisionViewRecordingGuard implements EntityValueReadGuardInterface
{
    /** @var list<int> */
    public array $viewIds = [];

    public function assertProtectedReadable(EntityBase $entity, string $field, object $viewIdentity): void
    {
        $this->viewIds[] = spl_object_id($viewIdentity);
    }

    public function invalidate(EntityBase $entity): void {}
}

final class SealedHydrationFixture extends ContentEntityBase
{
    public static int $constructorCalls = 0;
    public static int $fromStorageCalls = 0;

    public function __construct(array $values = [], string $entityTypeId = '', array $entityKeys = [], array $fieldDefinitions = [])
    {
        ++self::$constructorCalls;
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    public static function fromStorage(array $values, HydrationContext $context): static
    {
        ++self::$fromStorageCalls;

        return parent::fromStorage($values, $context);
    }
}
