<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityValueComparator;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Exception\InternalFieldArrayExportDenied;
use Waaseyaa\Entity\Exception\StaleEntityReadLayout;
use Waaseyaa\Entity\FieldReadLevel;

final class SealedEntityValueContainerTest extends TestCase
{
    #[Test]
    public function nameless_user_without_context_cannot_yield_internal_mail(): void
    {
        $entity = $this->sealedEntity([
            'uid' => 15,
            'name' => '',
            'mail' => 'member@example.test',
        ]);

        self::assertSame(15, $entity->get('uid'));
        self::assertSame(['mail', 'name', 'uid'], $entity->fieldNames());

        $this->expectException(FieldReadDenied::class);
        $entity->get('mail');
    }

    #[Test]
    public function stale_generation_blocks_even_public_values_before_lookup(): void
    {
        $generation = new EntityReadLayoutGeneration();
        $entity = $this->sealedEntity(['uid' => 15], $generation);
        self::assertSame(15, $entity->get('uid'));

        $generation->advance();

        $this->expectException(StaleEntityReadLayout::class);
        $entity->get('uid');
    }

    #[Test]
    public function arrays_preflight_internal_fields_and_clones_get_distinct_view_identities(): void
    {
        $guard = new RecordingEntityValueReadGuard();
        $entity = $this->sealedEntity([
            'uid' => 15,
            'name' => 'Member',
            'mail' => 'member@example.test',
        ], guard: $guard);

        self::assertSame('Member', $entity->get('name'));
        $clone = clone $entity;
        self::assertSame('Member', $clone->get('name'));
        self::assertCount(2, array_unique($guard->viewIds));

        $this->expectException(InternalFieldArrayExportDenied::class);
        $entity->toArray();
    }

    #[Test]
    public function internal_array_export_denial_happens_before_any_protected_read(): void
    {
        $guard = new RecordingEntityValueReadGuard();
        $entity = $this->sealedEntity([
            'uid' => 15,
            'name' => 'Member',
            'mail' => 'member@example.test',
        ], guard: $guard);

        try {
            $entity->toArray();
            self::fail('An entity containing an Internal field must not have a public array representation.');
        } catch (InternalFieldArrayExportDenied) {
            self::assertSame([], $guard->viewIds);
        }
    }

    #[Test]
    public function attached_structural_selectors_win_over_blank_compatibility_values(): void
    {
        $entity = new TestEntity(
            ['uid' => 15, 'bundle' => '', 'langcode' => ''],
            'user',
            ['id' => 'uid', 'bundle' => 'bundle', 'langcode' => 'langcode'],
        );
        $entity->_attachEntityStructure(new EntityStructure(
            entityTypeId: 'user',
            bundleId: 'user',
            id: 15,
            uuid: '018f5f20-0000-7000-8000-000000000001',
            activeLanguageId: 'en',
            defaultLanguageId: 'en',
            knownTranslationIds: ['en'],
            revisionId: 7,
            fieldNames: ['uid', 'bundle', 'langcode'],
        ));

        self::assertSame(15, $entity->id());
        self::assertSame('018f5f20-0000-7000-8000-000000000001', $entity->uuid());
        self::assertSame('user', $entity->bundle());
        self::assertSame('en', $entity->language());
    }

    #[Test]
    public function sealed_duplication_does_not_pass_values_through_subclass_reconstruction_hooks(): void
    {
        ObservingDuplicateEntity::$duplicateInstanceCalled = false;
        $generation = new EntityReadLayoutGeneration();
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: ['uid' => 15, 'mail' => 'member@example.test'],
            layout: new EntityReadLayout($generation, [
                'uid' => FieldReadLevel::Public,
                'mail' => FieldReadLevel::Internal,
            ]),
            structure: new EntityStructure(
                entityTypeId: 'user',
                bundleId: 'user',
                id: 15,
                uuid: null,
                fieldNames: ['uid', 'mail'],
            ),
            entityTypeId: 'user',
            entityKeys: ['id' => 'uid'],
        );
        $entity = $boundary->installer()->instantiate(ObservingDuplicateEntity::class, $payload);
        self::assertInstanceOf(ObservingDuplicateEntity::class, $entity);

        $copy = $entity->duplicate();

        self::assertFalse(ObservingDuplicateEntity::$duplicateInstanceCalled);
        self::assertSame(15, $copy->get('uid'));
        $this->expectException(FieldReadDenied::class);
        $copy->get('mail');
    }

    #[Test]
    public function closed_comparison_returns_names_only_and_preflights_layout_generation(): void
    {
        $comparator = new EntityValueComparator();
        $current = $this->sealedEntity(['uid' => 15, 'mail' => 'current@example.test']);
        $target = $this->sealedEntity(['uid' => 15, 'mail' => 'target@example.test']);

        $changed = $comparator->changedFieldNames($current, $target, ['uid', 'mail']);
        self::assertSame(['mail'], $changed);
        self::assertStringNotContainsString('example.test', json_encode($changed, JSON_THROW_ON_ERROR));

        $generation = new EntityReadLayoutGeneration();
        $stale = $this->sealedEntity(['uid' => 15], $generation);
        $generation->advance();

        $this->expectException(StaleEntityReadLayout::class);
        $comparator->matchingSubmittedFieldNames($stale, ['uid' => 15], ['uid']);
    }

    /** @param array<string, mixed> $values */
    private function sealedEntity(
        array $values,
        ?EntityReadLayoutGeneration $generation = null,
        ?EntityValueReadGuardInterface $guard = null,
    ): TestEntity {
        $generation ??= new EntityReadLayoutGeneration();
        $layout = new EntityReadLayout($generation, [
            'uid' => FieldReadLevel::Public,
            'name' => FieldReadLevel::Protected,
            'mail' => FieldReadLevel::Internal,
        ]);
        $structure = new EntityStructure(
            entityTypeId: 'user',
            bundleId: 'user',
            id: $values['uid'] ?? null,
            uuid: null,
            fieldNames: array_keys($values),
        );
        $boundary = new EntityInitializationBoundary();
        $payload = $boundary->factory()->seal(
            values: $values,
            layout: $layout,
            structure: $structure,
            entityTypeId: 'user',
            entityKeys: ['id' => 'uid', 'label' => 'name'],
            guard: $guard,
        );
        $entity = $boundary->installer()->instantiate(TestEntity::class, $payload);
        self::assertInstanceOf(TestEntity::class, $entity);

        return $entity;
    }
}

final class ObservingDuplicateEntity extends EntityBase
{
    public static bool $duplicateInstanceCalled = false;

    /** @param array<string, mixed> $values */
    protected function duplicateInstance(array $values): static
    {
        self::$duplicateInstanceCalled = true;

        return parent::duplicateInstance($values);
    }
}

final class RecordingEntityValueReadGuard implements EntityValueReadGuardInterface
{
    /** @var list<int> */
    public array $viewIds = [];

    public function assertProtectedReadable(\Waaseyaa\Entity\EntityBase $entity, string $field, object $viewIdentity): void
    {
        $this->viewIds[] = spl_object_id($viewIdentity);
    }

    public function invalidate(\Waaseyaa\Entity\EntityBase $entity): void {}
}
