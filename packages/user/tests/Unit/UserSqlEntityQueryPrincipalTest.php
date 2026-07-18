<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\QueryAccountPrincipalMismatchException;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

#[CoversClass(SqlEntityQuery::class)]
final class UserSqlEntityQueryPrincipalTest extends TestCase
{
    private AccountFieldReadScope $scope;

    private EntityRepository $repository;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(User::class);
        new SqlSchemaHandler($entityType, $database)->ensureTable();

        $this->scope = new AccountFieldReadScope();
        $handler = new EntityAccessHandler([new UserAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard($this->scope, $handler->checkProtectedFieldRead(...)));

        $storageBoundary = new StorageBoundary();
        $driver = new SqlStorageDriverV2(
            new SqlStorageDriver(new SingleConnectionResolver($database), 'uid'),
            $storageBoundary->driverRowFactory(),
            $storageBoundary->driverSnapshotReader(),
        );

        $this->repository = V2EntityRepositoryFactory::create(
            $entityType,
            $driver,
            new EventDispatcher(),
            database: $database,
            accessHandler: $handler,
            storageBoundary: $storageBoundary,
            fieldReadScope: $this->scope,
        );

        foreach ([
            ['name' => 'viewer', 'status' => 1, 'roles' => ['authenticated'], 'permissions' => ['access user profiles']],
            ['name' => 'active-member', 'status' => 1, 'roles' => ['authenticated'], 'permissions' => []],
            ['name' => 'inactive-member', 'status' => 0, 'roles' => ['authenticated'], 'permissions' => []],
        ] as $values) {
            $this->repository->save($this->repository->create($values), validate: false);
        }
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
    }

    #[Test]
    public function candidate_filter_uses_the_active_immutable_principal_and_drops_denied_users(): void
    {
        $sessionUser = new User([
            'uid' => 1,
            'status' => 1,
            'roles' => ['authenticated'],
            'permissions' => ['access user profiles'],
        ]);
        $principal = new AuthorizationPrincipal(
            1,
            true,
            ['authenticated'],
            ['access user profiles'],
            'viewer-claims-v1',
        );

        $ids = $this->scope->run(
            $principal,
            fn(): array => $this->repository->getQuery()
                ->setAccount($sessionUser)
                ->sort('uid', 'ASC')
                ->execute(),
        );

        self::assertSame([1, 2], $ids);
    }

    #[Test]
    public function candidate_filter_rejects_a_bound_account_from_another_active_identity(): void
    {
        $sessionUser = new User(['uid' => 1, 'status' => 1]);
        $otherPrincipal = new AuthorizationPrincipal(
            9,
            true,
            ['authenticated'],
            ['access user profiles'],
            'other-claims-v1',
        );

        $this->expectException(QueryAccountPrincipalMismatchException::class);
        $this->scope->run(
            $otherPrincipal,
            fn(): array => $this->repository->getQuery()
                ->setAccount($sessionUser)
                ->execute(),
        );
    }

    #[Test]
    public function a_live_entity_account_without_an_active_principal_gains_no_query_authority(): void
    {
        $sessionUser = new User([
            'uid' => 1,
            'status' => 1,
            'roles' => ['authenticated'],
            'permissions' => ['access user profiles'],
        ]);

        $this->expectException(FieldReadDenied::class);
        $this->repository->getQuery()
            ->setAccount($sessionUser)
            ->execute();
    }
}
