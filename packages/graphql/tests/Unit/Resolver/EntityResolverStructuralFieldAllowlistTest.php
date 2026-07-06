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
 * R15 (audit A11, structural sibling of R14): {@see EntityResolver::resolveList()}
 * accepts filter/sort arguments on ANY field-name string and applies them as raw
 * storage conditions. The R14 fold-in gates fields a dynamic
 * {@see \Waaseyaa\Access\FieldAccessPolicyInterface} forbids, but it cannot see
 * the STRUCTURAL classes the REST sibling's {@see \Waaseyaa\Api\JsonApiController::validateQueryFields()}
 * allowlist rejects:
 *
 *   1. An undeclared `_data` JSON key (no policy → Neutral → not Forbidden) —
 *      it resolves to `json_extract(_data, '$.<key>')` and becomes a
 *      filter-presence / `total` oracle over arbitrary blob keys.
 *   2. A declared field flagged `settings['internal'] => true` (e.g. the User
 *      `two_factor_secret`, OIDC `client_secret_hash`) — `internal` is a
 *      settings flag, not a FieldAccessPolicy, so R14 never fires. The secret's
 *      value is never returned yet is fully oracle-able via filter/sort.
 *   3. A credential-named field (`pass` / `password` / `password_hash`) with no
 *      FieldDefinition at all.
 *
 * The `/graphql` route is `allowAll()` (public), so the oracle is reachable at
 * anonymous-and-up privilege over any entity row the caller can entity-view.
 * This test drives each class; after the fix the resolver rejects them with a
 * `UserError` BEFORE any storage query runs, exactly as REST does.
 */
#[CoversClass(EntityResolver::class)]
final class EntityResolverStructuralFieldAllowlistTest extends TestCase
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
                // Declared but flagged internal — a secret by design. Never
                // returned in output, but oracle-able via filter/sort pre-fix.
                'two_factor_secret' => new FieldDefinition(
                    name: 'two_factor_secret',
                    type: 'string',
                    settings: ['internal' => true],
                ),
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
        // Entity-level view ALLOWED for all rows; no field policy at all, so R14
        // alone would let every structural class below through to storage.
        $policy = new class () implements AccessPolicyInterface {
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
        };

        $handler = new EntityAccessHandler([$policy]);

        return new EntityResolver($this->entityTypeManager, new GraphQlAccessGuard($handler, $this->account), $this->account);
    }

    private function seed(): void
    {
        foreach ([['A', 'TOTPSECRETA'], ['B', 'TOTPSECRETB']] as [$title, $secret]) {
            $entity = $this->storage->create(['title' => $title, 'two_factor_secret' => $secret]);
            $entity->enforceIsNew();
            $this->storage->save($entity);
        }
    }

    // --- Class 1: undeclared blob key ---

    #[Test]
    public function filteringOnUndeclaredFieldIsRejected(): void
    {
        $this->seed();

        $this->expectException(UserError::class);
        $this->expectExceptionMessage("Cannot filter by field 'nonexistent'");

        $this->resolver()->resolveList('article', [
            'filter' => [['field' => 'nonexistent', 'value' => 'anything']],
        ]);
    }

    #[Test]
    public function sortingOnUndeclaredFieldIsRejected(): void
    {
        $this->seed();

        $this->expectException(UserError::class);
        $this->expectExceptionMessage("Cannot sort by field 'nonexistent'");

        $this->resolver()->resolveList('article', ['sort' => 'nonexistent']);
    }

    // --- Class 2: declared internal-flagged field (the credential oracle) ---

    #[Test]
    public function filteringOnInternalFlaggedFieldIsRejected(): void
    {
        $this->seed();

        // Pre-fix this returned total=1 for a correct probe and total=0 for a
        // wrong one — a char-by-char oracle over a TOTP secret the caller may
        // never read. Post-fix it is rejected before the storage query runs.
        $this->expectException(UserError::class);
        $this->expectExceptionMessage("Cannot filter by field 'two_factor_secret'");

        $this->resolver()->resolveList('article', [
            'filter' => [['field' => 'two_factor_secret', 'value' => 'TOTPSECRETA']],
        ]);
    }

    #[Test]
    public function sortingOnInternalFlaggedFieldIsRejected(): void
    {
        $this->seed();

        $this->expectException(UserError::class);
        $this->expectExceptionMessage("Cannot sort by field 'two_factor_secret'");

        $this->resolver()->resolveList('article', ['sort' => 'two_factor_secret']);
    }

    // --- Class 3: credential floor (undeclared credential names) ---

    #[Test]
    public function filteringOnCredentialFieldIsRejected(): void
    {
        $this->seed();

        $this->expectException(UserError::class);
        $this->expectExceptionMessage("Cannot filter by field 'password_hash'");

        $this->resolver()->resolveList('article', [
            'filter' => [['field' => 'password_hash', 'value' => 'x']],
        ]);
    }

    // --- Positive control: declared, non-internal fields still filter/sort ---

    #[Test]
    public function filteringOnDeclaredFieldStillWorks(): void
    {
        $this->seed();

        $result = $this->resolver()->resolveList('article', [
            'filter' => [['field' => 'title', 'value' => 'A']],
        ]);

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
    }

    #[Test]
    public function sortingOnDeclaredFieldStillWorks(): void
    {
        $this->seed();

        $result = $this->resolver()->resolveList('article', ['sort' => 'title']);

        self::assertSame(2, $result['total']);
        self::assertCount(2, $result['items']);
    }

    #[Test]
    public function filteringOnEntityKeyStillWorks(): void
    {
        $this->seed();

        // Entity keys (id/uuid) are permitted even when not in the field-definition
        // map, mirroring REST's key union.
        $result = $this->resolver()->resolveList('article', [
            'filter' => [['field' => 'id', 'value' => '1']],
        ]);

        self::assertSame(1, $result['total']);
    }
}
