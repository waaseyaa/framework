<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;

#[CoversClass(SchemaPresenter::class)]
final class SchemaPresenterFieldAccessTest extends TestCase
{
    private SchemaPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new SchemaPresenter();
    }

    private function createEntityType(): EntityType
    {
        return new EntityType(
            id: 'article',
            label: 'Article',
            class: \Waaseyaa\Api\Tests\Fixtures\TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
    }

    private function createEntity(): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('article');
        return $entity;
    }

    private function createAccount(): AccountInterface
    {
        return $this->createMock(AccountInterface::class);
    }

    private function createFieldDefs(): array
    {
        return [
            'body' => ['type' => 'text', 'label' => 'Body'],
            'secret' => ['type' => 'string', 'label' => 'Secret'],
            'status' => ['type' => 'string', 'label' => 'Status'],
        ];
    }

    /**
     * Creates a policy: forbid viewing 'secret', forbid editing 'status'.
     */
    private function createPolicy(): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class () implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === 'secret' && $operation === 'view') {
                    return AccessResult::forbidden('hidden');
                }
                if ($fieldName === 'status' && $operation === 'edit') {
                    return AccessResult::forbidden('read-only');
                }
                return AccessResult::neutral();
            }
        };
    }

    #[Test]
    public function presentWithoutAccessContextReturnsAllFields(): void
    {
        $schema = $this->presenter->present($this->createEntityType(), $this->createFieldDefs());

        $this->assertArrayHasKey('body', $schema['properties']);
        $this->assertArrayHasKey('secret', $schema['properties']);
        $this->assertArrayHasKey('status', $schema['properties']);
    }

    #[Test]
    public function presentRemovesViewDeniedFields(): void
    {
        $accessHandler = new EntityAccessHandler([$this->createPolicy()]);

        $schema = $this->presenter->present(
            $this->createEntityType(),
            $this->createFieldDefs(),
            $this->createEntity(),
            $accessHandler,
            $this->createAccount(),
        );

        // 'secret' is view-denied — should be removed entirely.
        $this->assertArrayNotHasKey('secret', $schema['properties']);
        // 'body' and 'status' are view-allowed — should remain.
        $this->assertArrayHasKey('body', $schema['properties']);
        $this->assertArrayHasKey('status', $schema['properties']);
    }

    #[Test]
    public function presentMarksEditDeniedFieldsAsRestricted(): void
    {
        $accessHandler = new EntityAccessHandler([$this->createPolicy()]);

        $schema = $this->presenter->present(
            $this->createEntityType(),
            $this->createFieldDefs(),
            $this->createEntity(),
            $accessHandler,
            $this->createAccount(),
        );

        // 'status' is edit-denied — should be readOnly with x-access-restricted.
        $this->assertTrue($schema['properties']['status']['readOnly'] ?? false);
        $this->assertTrue($schema['properties']['status']['x-access-restricted'] ?? false);

        // 'body' is fully accessible — should NOT have restricted flag.
        $this->assertArrayNotHasKey('readOnly', $schema['properties']['body']);
        $this->assertArrayNotHasKey('x-access-restricted', $schema['properties']['body']);
    }

    #[Test]
    public function presentDoesNotTouchSystemProperties(): void
    {
        $accessHandler = new EntityAccessHandler([$this->createPolicy()]);

        $schema = $this->presenter->present(
            $this->createEntityType(),
            $this->createFieldDefs(),
            $this->createEntity(),
            $accessHandler,
            $this->createAccount(),
        );

        // System properties (id, uuid) should be unchanged.
        $this->assertTrue($schema['properties']['id']['readOnly']);
        $this->assertSame('hidden', $schema['properties']['id']['x-widget']);
        $this->assertArrayNotHasKey('x-access-restricted', $schema['properties']['id']);
    }
}
