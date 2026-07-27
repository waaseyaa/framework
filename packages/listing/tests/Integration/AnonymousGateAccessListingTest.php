<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Cache\ContextRegistry;
use Waaseyaa\Cache\ContextResolver;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Listing\EntityRepositoryRegistry;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\Tests\Contract\Fixtures\ArticleEntity;

/**
 * Regression: listing-backed pages served to anonymous visitors.
 *
 * ListingResolver passes `null` for the per-row gate user, relying on the
 * GateInterface contract ("Null means the current/anonymous user").
 * EntityAccessGate used to reject null outright, so every anonymous listing
 * came back empty while entity detail pages rendered fine. These tests pin
 * the fixed behaviour end-to-end through the real EntityAccessGate +
 * EntityAccessHandler + policy stack.
 */
#[CoversNothing]
final class AnonymousGateAccessListingTest extends TestCase
{
    #[Test]
    public function anonymousViewableRowsSurviveNullUserGateChecks(): void
    {
        $resolver = $this->buildResolver(new EntityAccessGate(
            new EntityAccessHandler([$this->anonymousViewablePolicy()]),
        ));

        $result = $resolver->resolve(new ListingDefinition(
            id: 'news',
            entityType: 'article',
            pageSize: 20,
        ));

        $ids = array_map(static fn(EntityInterface $row): string => (string) $row->id(), $result->rows);
        sort($ids, SORT_STRING);
        self::assertSame(['1', '2', '3'], $ids);
    }

    #[Test]
    public function nullUserGateChecksUseInstalledScopePrincipal(): void
    {
        $scope = new AccountFieldReadScope();
        $resolver = $this->buildResolver(new EntityAccessGate(
            new EntityAccessHandler([$this->authenticatedOnlyPolicy()]),
            fieldReadScope: $scope,
        ));
        $def = new ListingDefinition(
            id: 'members_only',
            entityType: 'article',
            pageSize: 20,
        );

        // Anonymous (no installed principal): the authenticated-only policy
        // filters every row, but the pipeline stays functional — no blanket deny.
        self::assertSame([], $resolver->resolve($def)->rows);

        // Authenticated principal installed for the request scope: rows return.
        $principal = new AuthorizationPrincipal(7, true, [], [], 'gen-1');
        $result = $scope->run($principal, static fn() => $resolver->resolve($def));
        self::assertCount(3, $result->rows);
    }

    private function buildResolver(EntityAccessGate $gate): ListingResolver
    {
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: ArticleEntity::class,
            storageClass: '',
            keys: ['id' => 'id', 'label' => 'title'],
        );
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType($entityType);

        $driver = new InMemoryStorageDriver();
        foreach ([
            ['id' => '1', 'title' => 'a', 'status' => 1, 'weight' => 10],
            ['id' => '2', 'title' => 'b', 'status' => 1, 'weight' => 20],
            ['id' => '3', 'title' => 'c', 'status' => 1, 'weight' => 30],
        ] as $row) {
            $driver->write('article', (string) $row['id'], $row);
        }

        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create($entityType, $driver, new EventDispatcher());

        return new ListingResolver(
            repositories: new EntityRepositoryRegistry(['article' => $repo]),
            gate: $gate,
            contextResolver: new ContextResolver(new ContextRegistry()),
            entityTypes: $manager,
            requestContext: new RequestContext(
                roles: [],
                accountId: null,
                activeLangcode: null,
                interfaceLangcode: null,
                queryParams: [],
            ),
        );
    }

    private function anonymousViewablePolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'view'
                    ? AccessResult::allowed()
                    : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }
        };
    }

    private function authenticatedOnlyPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'view' && $account->isAuthenticated()
                    ? AccessResult::allowed()
                    : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }
        };
    }
}
