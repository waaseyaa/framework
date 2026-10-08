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
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\TaggedCacheInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Listing\HasListingsInterface;
use Waaseyaa\Listing\ListingCacheProjection;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\ListingDefinitionRegistry;
use Waaseyaa\Listing\ListingResolver;
use Waaseyaa\Listing\Pagination;
use Waaseyaa\Listing\ServiceProvider;
use Waaseyaa\Listing\Tests\Contract\Fixtures\ArticleEntity;

#[CoversNothing]
final class ListingLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        LifecycleAppProvider::$cache = null;
    }

    /** @return array{ListingResolver, \Waaseyaa\EntityStorage\EntityRepository, AccountFieldReadScope, EventDispatcher} */
    private function boot(InMemoryStorageDriver $driver, MemoryBackend $cache, AccessPolicyInterface $policy): array
    {
        LifecycleAppProvider::$cache = $cache;
        $dispatcher = new EventDispatcher();
        $manager = new EntityTypeManager(
            eventDispatcher: $dispatcher,
            fieldRegistry: new FieldDefinitionRegistry(),
            repositoryFactory: static fn(string $id, EntityType $type) => V2EntityRepositoryFactory::create($type, $driver, $dispatcher),
        );
        $handler = new EntityAccessHandler([$policy]);
        $scope = new AccountFieldReadScope();
        $registry = new ProviderRegistry(new NullLogger());
        $providers = $registry->discoverAndRegister(
            manifest: new PackageManifest(providers: [ServiceProvider::class, LifecycleAppProvider::class]),
            projectRoot: dirname(__DIR__, 4),
            config: [],
            entityTypeManager: $manager,
            database: DBALDatabase::createSqlite(':memory:'),
            dispatcher: $dispatcher,
            accessHandlerAccessor: static fn() => $handler,
            fieldReadScope: $scope,
            requestContext: new RequestContext(accountId: 7, queryParams: ['page' => '2']),
        );
        $registry->boot($providers);
        self::assertSame('lifecycle', $providers[0]->resolve(ListingDefinitionRegistry::class)->get('lifecycle')->id);

        return [$providers[0]->resolve(ListingResolver::class), $manager->getRepository('article'), $scope, $dispatcher];
    }

    private function seed(): InMemoryStorageDriver
    {
        $driver = new InMemoryStorageDriver();
        foreach (range(1, 5) as $id) {
            $driver->write('article', (string) $id, ['id' => (string) $id, 'title' => 'Article ' . $id]);
        }

        return $driver;
    }

    #[Test]
    public function requestBootsUseCurrentPermissionsAndDensePagination(): void
    {
        $driver = $this->seed();
        $cache = new MemoryBackend();
        $policy = new class implements AccessPolicyInterface {
            public function appliesTo(string $id): bool
            {
                return $id === 'article';
            }
            public function createAccess(string $id, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $account->hasPermission('view articles') ? AccessResult::allowed() : AccessResult::forbidden();
            }
        };
        $def = new ListingDefinition(id: 'lifecycle', entityType: 'article', pageSize: 2);
        foreach ([true, false, true] as $allowed) {
            [$resolver, , $scope] = $this->boot($driver, $cache, $policy);
            $principal = new AuthorizationPrincipal(7, true, [], $allowed ? ['view articles'] : [], $allowed ? 'allow' : 'deny');
            $result = $scope->run($principal, static fn() => $resolver->resolve($def));
            self::assertSame($allowed ? ['3', '4'] : [], array_map(static fn(EntityInterface $row): string => (string) $row->id(), $result->rows));
            self::assertSame($allowed ? 5 : 0, $result->pagination->totalRows);
            self::assertSame($allowed ? 2 : 1, $result->pagination->page);
            self::assertSame($allowed, $result->pagination->hasNext);
        }
    }

    #[Test]
    public function mutablePolicyRecomputesRowsOutsideTheRequestedPage(): void
    {
        $driver = $this->seed();
        $cache = new MemoryBackend();
        $policy = new class implements AccessPolicyInterface {
            public array $denied = [];
            public function appliesTo(string $id): bool
            {
                return $id === 'article';
            }
            public function createAccess(string $id, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return in_array((string) $entity->id(), $this->denied, true) ? AccessResult::forbidden() : AccessResult::allowed();
            }
        };
        [$resolver] = $this->boot($driver, $cache, $policy);
        $def = new ListingDefinition(id: 'lifecycle', entityType: 'article', pageSize: 2);
        self::assertSame(5, $resolver->resolve($def)->pagination->totalRows);
        $policy->denied = ['1', '3', '4'];
        $result = $resolver->resolve($def);
        self::assertSame(['2', '5'], array_map(static fn(EntityInterface $row): string => (string) $row->id(), $result->rows));
        self::assertSame(2, $result->pagination->totalRows);
        self::assertSame(1, $result->pagination->page);
        self::assertFalse($result->pagination->hasNext);
        $policy->denied = [];
        self::assertSame(5, $resolver->resolve($def)->pagination->totalRows);
    }

    #[Test]
    public function canonicalDeleteEvictsTypeTagAndRefusalDoesNot(): void
    {
        $cache = new MemoryBackend();
        $policy = new class implements AccessPolicyInterface {
            public function appliesTo(string $id): bool
            {
                return true;
            }
            public function createAccess(string $id, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        };
        [, $repository, , $dispatcher] = $this->boot($this->seed(), $cache, $policy);
        $cache->setWithTags('sentinel', new ListingCacheProjection(['1'], new Pagination(1, 1, 5, 5, false, true), ['entity:article'], []), ['entity:article']);
        $refusal = static function (): void {
            throw new \RuntimeException('refused');
        };
        $dispatcher->addListener(EntityEvents::PRE_DELETE->value, $refusal);
        try {
            $repository->delete($repository->find('5'));
            self::fail('Delete guard must refuse.');
        } catch (\RuntimeException $error) {
            self::assertSame('refused', $error->getMessage());
        }
        self::assertNotFalse($cache->get('sentinel'));
        self::assertNotNull($repository->find('5'));
        $dispatcher->removeListener(EntityEvents::PRE_DELETE->value, $refusal);
        $repository->delete($repository->find('5'));
        self::assertFalse($cache->get('sentinel'));
        self::assertNull($repository->find('5'));
    }
}

final class LifecycleAppProvider extends \Waaseyaa\Foundation\ServiceProvider\ServiceProvider implements HasListingsInterface
{
    public static ?MemoryBackend $cache = null;

    public function register(): void
    {
        $this->entityType(new EntityType(id: 'article', label: 'Article', class: ArticleEntity::class, keys: ['id' => 'id', 'label' => 'title']));
        $this->singleton(TaggedCacheInterface::class, static fn() => self::$cache);
    }

    public function listings(): array
    {
        return [new ListingDefinition(id: 'lifecycle', entityType: 'article', pageSize: 2)];
    }
}
