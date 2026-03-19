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
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;

#[CoversClass(EntityResolver::class)]
final class EntityResolverTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn () => $this->storage,
        );
        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \Waaseyaa\Api\Tests\Fixtures\TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'id' => ['type' => 'integer'],
                'uuid' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'boolean'],
            ],
        ));

        $this->account = $this->createStub(AccountInterface::class);
        $this->account->method('id')->willReturn(1);
        $this->account->method('hasPermission')->willReturn(true);
        $this->account->method('getRoles')->willReturn(['authenticated']);
        $this->account->method('isAuthenticated')->willReturn(true);
    }

    private function createResolver(EntityAccessHandler $handler): EntityResolver
    {
        $guard = new GraphQlAccessGuard($handler, $this->account);

        return new EntityResolver($this->entityTypeManager, $guard);
    }

    private function openAccessHandler(): EntityAccessHandler
    {
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                return AccessResult::neutral();
            }
        };

        return new EntityAccessHandler([$policy]);
    }

    private function seedArticle(string $title, bool $status = true): EntityInterface
    {
        $entity = $this->storage->create(['title' => $title, 'status' => $status]);
        $entity->enforceIsNew();
        $this->storage->save($entity);

        return $entity;
    }

    // ── resolveList ──────────────────────────────────────────────

    #[Test]
    public function resolveListReturnsAllEntities(): void
    {
        $this->seedArticle('First');
        $this->seedArticle('Second');

        $resolver = $this->createResolver($this->openAccessHandler());
        $result = $resolver->resolveList('article', []);

        self::assertCount(2, $result['items']);
        self::assertSame(2, $result['total']);
    }

    #[Test]
    public function resolveListFiltersOutDeniedEntities(): void
    {
        $this->seedArticle('Visible');
        $this->seedArticle('Hidden');

        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                $values = $entity->toArray();

                return ($values['title'] ?? '') === 'Visible'
                    ? AccessResult::allowed()
                    : AccessResult::neutral();
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
                return AccessResult::neutral();
            }
        };

        $resolver = $this->createResolver(new EntityAccessHandler([$policy]));
        $result = $resolver->resolveList('article', []);

        // total reflects accessible items when full result fits in one page
        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
        self::assertSame('Visible', $result['items'][0]['title']);
    }

    #[Test]
    public function resolveListAppliesFieldLevelFiltering(): void
    {
        $this->seedArticle('Test');

        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                return $fieldName === 'status' ? AccessResult::forbidden('secret') : AccessResult::neutral();
            }
        };

        $resolver = $this->createResolver(new EntityAccessHandler([$policy]));
        $result = $resolver->resolveList('article', []);

        self::assertCount(1, $result['items']);
        self::assertArrayHasKey('title', $result['items'][0]);
        self::assertArrayNotHasKey('status', $result['items'][0]);
    }

    #[Test]
    public function resolveListClampsLimitToMaximum(): void
    {
        $this->seedArticle('One');

        $resolver = $this->createResolver($this->openAccessHandler());

        // Requesting limit > MAX_LIMIT (100) should not throw; just clamp
        $result = $resolver->resolveList('article', ['limit' => 999]);
        self::assertCount(1, $result['items']);
    }

    #[Test]
    public function resolveListClampsLimitMinimumToOne(): void
    {
        $this->seedArticle('One');

        $resolver = $this->createResolver($this->openAccessHandler());
        $result = $resolver->resolveList('article', ['limit' => 0]);

        // limit=0 is clamped to 1, so we still get the entity
        self::assertCount(1, $result['items']);
    }

    #[Test]
    public function resolveListClampsNegativeOffsetToZero(): void
    {
        $this->seedArticle('One');

        $resolver = $this->createResolver($this->openAccessHandler());
        $result = $resolver->resolveList('article', ['offset' => -5]);

        self::assertCount(1, $result['items']);
    }

    #[Test]
    public function resolveListThrowsOnMalformedFilter(): void
    {
        $resolver = $this->createResolver($this->openAccessHandler());

        $this->expectException(UserError::class);
        $this->expectExceptionMessage("each entry must have 'field' and 'value'");

        $resolver->resolveList('article', [
            'filter' => [['bad_key' => 'no_field']],
        ]);
    }

    #[Test]
    public function resolveListThrowsOnInvalidFilterOperator(): void
    {
        $resolver = $this->createResolver($this->openAccessHandler());

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Invalid filter operator');

        $resolver->resolveList('article', [
            'filter' => [['field' => 'title', 'value' => 'x', 'operator' => 'NOPE']],
        ]);
    }

    #[Test]
    public function resolveListParsesDescendingSort(): void
    {
        $this->seedArticle('Alpha');
        $this->seedArticle('Beta');

        $resolver = $this->createResolver($this->openAccessHandler());
        $result = $resolver->resolveList('article', ['sort' => '-title']);

        self::assertSame('Beta', $result['items'][0]['title']);
        self::assertSame('Alpha', $result['items'][1]['title']);
    }

    #[Test]
    public function resolveListParsesAscendingSort(): void
    {
        $this->seedArticle('Beta');
        $this->seedArticle('Alpha');

        $resolver = $this->createResolver($this->openAccessHandler());
        $result = $resolver->resolveList('article', ['sort' => 'title']);

        self::assertSame('Alpha', $result['items'][0]['title']);
        self::assertSame('Beta', $result['items'][1]['title']);
    }

    #[Test]
    public function resolveListWithValidFilter(): void
    {
        $this->seedArticle('Match');
        $this->seedArticle('NoMatch');

        $resolver = $this->createResolver($this->openAccessHandler());
        $result = $resolver->resolveList('article', [
            'filter' => [['field' => 'title', 'value' => 'Match']],
        ]);

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
        self::assertSame('Match', $result['items'][0]['title']);
    }

    // ── resolveSingle ────────────────────────────────────────────

    #[Test]
    public function resolveSingleReturnsEntity(): void
    {
        $entity = $this->seedArticle('Hello');
        $resolver = $this->createResolver($this->openAccessHandler());

        $data = $resolver->resolveSingle('article', $entity->id());

        self::assertNotNull($data);
        self::assertSame('Hello', $data['title']);
        self::assertSame(0, $data['_graphql_depth']);
    }

    #[Test]
    public function resolveSingleReturnsNullForNonexistent(): void
    {
        $resolver = $this->createResolver($this->openAccessHandler());

        self::assertNull($resolver->resolveSingle('article', 999));
    }

    #[Test]
    public function resolveSingleReturnsNullWhenAccessDenied(): void
    {
        $entity = $this->seedArticle('Secret');

        // No policies = Neutral = denied at entity level
        $resolver = $this->createResolver(new EntityAccessHandler([]));

        self::assertNull($resolver->resolveSingle('article', $entity->id()));
    }

    // ── resolveCreate ────────────────────────────────────────────

    #[Test]
    public function resolveCreatePersistsEntity(): void
    {
        $resolver = $this->createResolver($this->openAccessHandler());
        $result = $resolver->resolveCreate('article', ['title' => 'New']);

        self::assertSame('New', $result['title']);
        self::assertArrayHasKey('id', $result);
        self::assertSame(0, $result['_graphql_depth']);

        // Verify persistence
        $loaded = $this->storage->load($result['id']);
        self::assertNotNull($loaded);
    }

    #[Test]
    public function resolveCreateThrowsWhenAccessDenied(): void
    {
        $resolver = $this->createResolver(new EntityAccessHandler([]));

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('cannot create');

        $resolver->resolveCreate('article', ['title' => 'Forbidden']);
    }

    #[Test]
    public function resolveCreateThrowsWhenFieldEditForbidden(): void
    {
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                return $fieldName === 'status' && $operation === 'edit'
                    ? AccessResult::forbidden('read-only field')
                    : AccessResult::neutral();
            }
        };

        $resolver = $this->createResolver(new EntityAccessHandler([$policy]));

        $this->expectException(UserError::class);
        $this->expectExceptionMessage("cannot edit field 'status'");

        $resolver->resolveCreate('article', ['title' => 'OK', 'status' => true]);
    }

    // ── resolveUpdate ────────────────────────────────────────────

    #[Test]
    public function resolveUpdateModifiesEntity(): void
    {
        $entity = $this->seedArticle('Old');
        $resolver = $this->createResolver($this->openAccessHandler());

        $result = $resolver->resolveUpdate('article', $entity->id(), ['title' => 'New']);

        self::assertSame('New', $result['title']);
    }

    #[Test]
    public function resolveUpdateThrowsForNonexistentEntity(): void
    {
        $resolver = $this->createResolver($this->openAccessHandler());

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Entity not found');

        $resolver->resolveUpdate('article', 999, ['title' => 'Ghost']);
    }

    #[Test]
    public function resolveUpdateThrowsWhenAccessDenied(): void
    {
        $entity = $this->seedArticle('Protected');
        $resolver = $this->createResolver(new EntityAccessHandler([]));

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('cannot update');

        $resolver->resolveUpdate('article', $entity->id(), ['title' => 'Hacked']);
    }

    // ── resolveDelete ────────────────────────────────────────────

    #[Test]
    public function resolveDeleteRemovesEntity(): void
    {
        $entity = $this->seedArticle('Doomed');
        $resolver = $this->createResolver($this->openAccessHandler());

        $result = $resolver->resolveDelete('article', $entity->id());

        self::assertTrue($result);
        self::assertNull($this->storage->load($entity->id()));
    }

    #[Test]
    public function resolveDeleteThrowsForNonexistentEntity(): void
    {
        $resolver = $this->createResolver($this->openAccessHandler());

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('Entity not found');

        $resolver->resolveDelete('article', 999);
    }

    #[Test]
    public function resolveDeleteThrowsWhenAccessDenied(): void
    {
        $entity = $this->seedArticle('Protected');
        $resolver = $this->createResolver(new EntityAccessHandler([]));

        $this->expectException(UserError::class);
        $this->expectExceptionMessage('cannot delete');

        $resolver->resolveDelete('article', $entity->id());
    }
}
