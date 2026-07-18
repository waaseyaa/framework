<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\Entity\Exception\EntitySerializationForbidden;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Hydration\HydrationContext;
use Waaseyaa\Entity\Snapshot\EntityValuesSnapshot;

#[CoversClass(EntityValuesSnapshot::class)]
final class EntityValuesSnapshotTest extends TestCase
{
    #[Test]
    public function sealed_non_public_values_cannot_be_detached_into_an_unguarded_snapshot(): void
    {
        $guard = new SnapshotPermitCountingGuard();
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: ['id' => 1, 'name' => 'Protected name'],
            layout: new EntityReadLayout(new EntityReadLayoutGeneration(), [
                'id' => FieldReadLevel::Public,
                'name' => FieldReadLevel::Protected,
            ]),
            structure: new EntityStructure('user', 'user', 1, null, fieldNames: ['id', 'name']),
            entityTypeId: 'user',
            entityKeys: ['id' => 'id', 'label' => 'name'],
            guard: $guard,
        );
        $entity = $boundary->installer()->instantiate(TestEntity::class, $payload);

        try {
            EntityValuesSnapshot::fromEntity($entity, new HydrationContext('user', ['id' => 'id', 'label' => 'name']));
            self::fail('A sealed non-Public value must not be copied into an unguarded snapshot.');
        } catch (EntitySerializationForbidden) {
            self::assertSame(0, $guard->reads, 'Snapshot rejection must happen before any protected value read.');
        }
    }

    #[Test]
    public function fromEntityCapturesShallowStorageBag(): void
    {
        $entity = new TestEntity(
            values: ['id' => 1, 'label' => 'Hi', 'extra' => 'x'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        $ctx = new HydrationContext('test_entity', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label']);
        $snap = EntityValuesSnapshot::fromEntity($entity, $ctx);

        $this->assertTrue($snap->has('label'));
        $this->assertSame('Hi', $snap->get('label'));
        $this->assertSame('x', $snap->get('extra'));
        $this->assertSame($ctx, $snap->context());
    }

    #[Test]
    public function toStorageArrayReturnsIndependentTopLevelBag(): void
    {
        $entity = new TestEntity(
            values: ['id' => 1, 'label' => 'A'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        $snap = EntityValuesSnapshot::fromEntity(
            $entity,
            new HydrationContext('t', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label']),
        );
        $out = $snap->toStorageArray();
        $out['label'] = 'Mutated';

        $this->assertSame('A', $entity->label());
    }

    #[Test]
    public function getCastAwareThrowsWhenNoCastMapProvided(): void
    {
        $entity = new TestEntity(
            values: ['id' => 1],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        $snap = EntityValuesSnapshot::fromEntity(
            $entity,
            new HydrationContext('t', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label']),
        );

        $this->expectException(\LogicException::class);
        $snap->getCastAware('n');
    }

    #[Test]
    public function getCastAwareUsesValueCasterWhenCastMapInjected(): void
    {
        $entity = new class (['title' => 't', 'n' => '7']) extends \Waaseyaa\Entity\ContentEntityBase {
            protected array $casts = ['n' => 'int'];

            public function __construct(array $values = [])
            {
                parent::__construct($values, 'article', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'bundle']);
            }
        };
        $snap = EntityValuesSnapshot::fromEntity(
            $entity,
            new HydrationContext('article', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'bundle']),
            casts: ['n' => 'int'],
        );

        $this->assertSame(7, $snap->getCastAware('n'));
        $this->assertSame('7', $snap->get('n'));
    }

    #[Test]
    public function fromEntityAndTypeBuildsContextFromEntityType(): void
    {
        $type = new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: ['id' => 'nid', 'label' => 'title'],
        );
        $entity = new TestEntity(
            values: ['nid' => 5, 'title' => 'X'],
            entityTypeId: 'node',
            entityKeys: ['id' => 'nid', 'label' => 'title'],
        );
        $snap = EntityValuesSnapshot::fromEntityAndType($entity, $type);

        $this->assertSame('node', $snap->context()->entityTypeId);
        $this->assertSame(['id' => 'nid', 'label' => 'title'], $snap->context()->entityKeys);
        $this->assertSame('X', $snap->get('title'));
    }
}

final class SnapshotPermitCountingGuard implements EntityValueReadGuardInterface
{
    public int $reads = 0;

    public function assertProtectedReadable(\Waaseyaa\Entity\EntityBase $entity, string $field, object $viewIdentity): void
    {
        ++$this->reads;
    }

    public function invalidate(\Waaseyaa\Entity\EntityBase $entity): void {}
}
