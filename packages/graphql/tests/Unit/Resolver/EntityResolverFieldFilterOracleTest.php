<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Resolver;

use GraphQL\Error\UserError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;

/**
 * R14 GraphQL fold-in (audit A11): {@see EntityResolver::resolveList()} applies
 * caller-supplied filter/sort arguments as raw storage conditions and computes
 * `total` with only the entity-level `guard->canView()` predicate. A field
 * gated by a dynamic {@see FieldAccessPolicyInterface} (a classification /
 * clearance field, structurally normal) therefore becomes the same
 * presence/ordering oracle the REST sibling had (R13 WP1 flagged this path).
 *
 * The GraphQL list endpoint accepts filter arguments on any field string, so a
 * caller who may list the type and view its rows but lacks a field's clearance
 * can filter on it and read `total` as a per-value row count. After the fix the
 * field-access gate excludes those entities value-independently, closing it.
 */
#[CoversClass(EntityResolver::class)]
final class EntityResolverFieldFilterOracleTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');
        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );
        $this->entityTypeManager->registerCoreEntityType(TestEntityType::stub(
            'article',
            [
                'id' => new FieldDefinition(name: 'id', type: 'integer'),
                'uuid' => new FieldDefinition(name: 'uuid', type: 'string'),
                'title' => new FieldDefinition(name: 'title', type: 'string'),
                // Declared, non-internal field — only the policy below restricts it.
                'secret' => new FieldDefinition(name: 'secret', type: 'string'),
            ],
            keys: TestEntity::definitionKeys(),
            class: TestEntity::class,
            label: 'Article',
        ));

        $this->account = $this->createStub(AccountInterface::class);
        $this->account->method('id')->willReturn(1);
        $this->account->method('hasPermission')->willReturn(true);
        $this->account->method('isAuthenticated')->willReturn(true);
    }

    private function resolver(): EntityResolver
    {
        // Entity-level view ALLOWED; field-level view of `secret` FORBIDDEN.
        $policy = new class () implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                return true;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return $fieldName === 'secret' && $operation === 'view'
                    ? AccessResult::forbidden('No view access to secret')
                    : AccessResult::neutral();
            }
        };

        $handler = new EntityAccessHandler([$policy]);

        return new EntityResolver($this->entityTypeManager, new GraphQlAccessGuard($handler, $this->account), $this->account);
    }

    private function seed(): void
    {
        foreach ([['A', 'classified'], ['B', 'classified'], ['C', 'public']] as [$title, $secret]) {
            $entity = $this->storage->create(['title' => $title, 'secret' => $secret]);
            $entity->enforceIsNew();
            $this->storage->save($entity);
        }
    }

    #[Test]
    public function filteringOnViewForbiddenFieldLeaksNoTotalSignal(): void
    {
        $this->seed();

        $result = $this->resolver()->resolveList('article', [
            'filter' => [['field' => 'secret', 'value' => 'classified']],
        ]);

        self::assertSame(0, $result['total'], 'total must not leak the count of rows matching a view-forbidden filter field');
        self::assertCount(0, $result['items'], 'no item may surface from a view-forbidden filter field');
    }

    #[Test]
    public function forbiddenFilterValuesAreIndistinguishable(): void
    {
        $this->seed();

        $match = $this->resolver()->resolveList('article', ['filter' => [['field' => 'secret', 'value' => 'classified']]]);
        $miss = $this->resolver()->resolveList('article', ['filter' => [['field' => 'secret', 'value' => 'absent']]]);

        self::assertSame($match['total'], $miss['total']);
        self::assertSame(0, $match['total']);
    }

    #[Test]
    public function filteringOnReadableFieldStillWorks(): void
    {
        $this->seed();

        $result = $this->resolver()->resolveList('article', ['filter' => [['field' => 'title', 'value' => 'A']]]);

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
    }

    #[Test]
    public function sortingOnViewForbiddenFieldIsRejected(): void
    {
        $this->seed();

        // Storage sort/pagination run before the value-independent drop, so a
        // sort on a view-forbidden field is rejected rather than allowed to
        // order rows into observable pagination ranks.
        $this->expectException(UserError::class);
        $this->expectExceptionMessage("Cannot sort by field 'secret'");

        $this->resolver()->resolveList('article', ['sort' => 'secret']);
    }

    #[Test]
    public function sortingOnReadableFieldStillWorks(): void
    {
        $this->seed();

        // No availability regression: sorting on a readable field is unaffected.
        $result = $this->resolver()->resolveList('article', ['sort' => 'title']);

        self::assertSame(3, $result['total']);
        self::assertCount(3, $result['items']);
    }
}
