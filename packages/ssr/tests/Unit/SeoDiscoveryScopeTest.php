<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Seo\Discovery\PublicUrlPolicyInterface;
use Waaseyaa\SSR\Http\SeoPublicController;

/**
 * The crawler surfaces establish the ANONYMOUS FIELD-READ SCOPE, not merely an
 * anonymous query account.
 *
 * These are two different guarantees and only one of them was previously
 * covered. `setAccount()` binds the account the entity QUERY filters by, and
 * several tests pin it. The field-read scope is separate: it is the ambient
 * principal that governs FIELD reads, which is what an application's URL policy
 * sees when it reads a slug, a parent reference, or any other field while
 * building a canonical path. If the controller stopped establishing it, the
 * enumeration would inherit whatever principal the request left in scope, and an
 * application policy would silently classify entities as the REQUESTING VIEWER
 * rather than as an anonymous crawler.
 *
 * An audit found that deleting the scope establishment from
 * `SeoPublicController::runAsAnonymous()` broke no test in this repository or in
 * its downstream consumer. This class closes that gap: both tests below turn red
 * when that wrapper is removed, the first because the ambient principal becomes
 * null and the second because it becomes the caller's authenticated principal.
 *
 * @see \Waaseyaa\Seo\SitemapGenerator::collectFromEntityTypes() for the account
 *      binding, which is the other half and is pinned separately.
 */
#[CoversClass(SeoPublicController::class)]
final class SeoDiscoveryScopeTest extends TestCase
{
    private function entityTypeManager(): EntityTypeManager
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($database);
        $accessHandler = new EntityAccessHandler();
        $accessHandler->addPolicy(new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $operation === 'view' ? AccessResult::allowed('public') : AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        });

        $manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $database, $accessHandler): EntityRepository {
                new SqlSchemaHandler($definition, $database)->ensureTable();

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver),
                    $dispatcher,
                    database: $database,
                    accessHandler: $accessHandler,
                );
            },
        );

        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));
        $repository = $manager->getRepository('article');
        $repository->save($repository->create(['title' => 'One', 'status' => 1]), validate: false);

        return $manager;
    }

    /**
     * A URL policy that records the ambient field-read principal it is called
     * with. This is the exact position an application policy occupies, so what
     * it observes is what the application would observe.
     *
     * @return array{0: SeoPublicController, 1: object}
     */
    private function controllerWithScopeSpy(AccountFieldReadScope $scope): array
    {
        $spy = new class ($scope) implements PublicUrlPolicyInterface {
            /** @var list<?AuthorizationPrincipalInterface> */
            public array $observed = [];

            public function __construct(private readonly AccountFieldReadScope $scope) {}

            public function canonicalPath(EntityInterface $entity): ?string
            {
                $this->observed[] = $this->scope->current();

                return '/a/' . $entity->id();
            }

            public function markdownPath(EntityInterface $entity): ?string
            {
                $this->observed[] = $this->scope->current();

                return null;
            }
        };

        $controller = new SeoPublicController(
            $this->entityTypeManager(),
            $scope,
            new AccountPrincipalFactory(),
            \Waaseyaa\SSR\Http\CanonicalPublicOrigin::tryFrom('https://example.com'),
            $spy,
        );

        return [$controller, $spy];
    }

    #[Test]
    public function enumeration_establishes_an_anonymous_field_read_scope_where_none_existed(): void
    {
        $scope = new AccountFieldReadScope();
        [$controller, $spy] = $this->controllerWithScopeSpy($scope);

        self::assertNull($scope->current(), 'precondition: no ambient principal before the request');

        $controller->sitemapXml();

        self::assertNotSame([], $spy->observed, 'the URL policy must actually have been consulted');
        foreach ($spy->observed as $principal) {
            // Red when runAsAnonymous() stops establishing the scope: the policy
            // then runs with NO ambient principal at all.
            self::assertNotNull($principal, 'the enumeration must establish a field-read scope');
            self::assertFalse($principal->isAuthenticated(), 'the established principal must be anonymous');
            self::assertSame(0, $principal->id(), 'the established principal must be the anonymous account');
        }

        self::assertNull($scope->current(), 'the scope must be unwound after the response');
    }

    #[Test]
    public function enumeration_overrides_an_authenticated_ambient_principal(): void
    {
        $scope = new AccountFieldReadScope();
        [$controller, $spy] = $this->controllerWithScopeSpy($scope);

        $viewer = new AuthorizationPrincipal(4242, true, ['band_member'], ['view members'], 'session-generation');

        $restoredInsideCaller = $scope->run($viewer, function () use ($controller, $scope): int|string|null {
            $controller->sitemapXml();
            $controller->llmsTxt();

            // Still inside the caller's own scope: the enumeration must have
            // unwound its anonymous scope and handed this one back.
            return $scope->current()?->id();
        });

        self::assertNotSame([], $spy->observed);
        foreach ($spy->observed as $principal) {
            // Red when the wrapper is removed: the policy then observes 4242 and
            // the crawler surface becomes viewer-dependent.
            self::assertNotNull($principal);
            self::assertNotSame(4242, $principal->id(), 'enumeration must not run as the requesting viewer');
            self::assertFalse($principal->isAuthenticated(), 'enumeration must be anonymous regardless of the caller');
        }

        self::assertSame(4242, $restoredInsideCaller, 'the caller scope must be restored after enumeration');
        self::assertNull($scope->current(), 'and fully unwound once the caller scope closes');
    }
}
