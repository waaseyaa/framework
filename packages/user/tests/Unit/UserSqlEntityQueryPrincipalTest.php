<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProjectedProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Exception\StaleEntityReadLayout;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\ProtectedEntityReadProjectionException;
use Waaseyaa\EntityStorage\Exception\QueryAccountPrincipalMismatchException;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\User\UserEntityReadPolicy;

#[CoversClass(SqlEntityQuery::class)]
final class UserSqlEntityQueryPrincipalTest extends TestCase
{
    private AccountFieldReadScope $scope;

    private EntityRepository $repository;

    private DBALDatabase $database;

    private EntityType $entityType;

    private EntityAccessHandler $handler;

    private FieldDefinitionRegistry $fieldRegistry;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->entityType = EntityType::fromClass(User::class);
        new SqlSchemaHandler($this->entityType, $this->database)->ensureTable();

        $this->fieldRegistry = new FieldDefinitionRegistry();
        $this->fieldRegistry->registerCoreFields('user', $this->entityType->getFieldDefinitions());

        $this->scope = new AccountFieldReadScope();
        $this->handler = new EntityAccessHandler([new UserAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard($this->scope, $this->handler->checkProtectedFieldRead(...)));

        $storageBoundary = new StorageBoundary();
        $driver = new SqlStorageDriverV2(
            new SqlStorageDriver(new SingleConnectionResolver($this->database), 'uid'),
            $storageBoundary->driverRowFactory(),
            $storageBoundary->driverSnapshotReader(),
        );

        $this->repository = V2EntityRepositoryFactory::create(
            $this->entityType,
            $driver,
            new EventDispatcher(),
            database: $this->database,
            fieldRegistry: $this->fieldRegistry,
            accessHandler: $this->handler,
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
    public function protected_candidate_projection_does_not_construct_user_entities(): void
    {
        $principal = new AuthorizationPrincipal(
            1,
            true,
            ['authenticated'],
            ['access user profiles'],
            'viewer-claims-v1',
        );
        $query = new SqlEntityQuery(
            $this->entityType,
            $this->database,
            fieldRegistry: $this->fieldRegistry,
            fieldReadScope: $this->scope,
        )
            ->withAccessHandler($this->handler)
            ->withEntityLoader(static function (array $ids): array {
                self::fail('A complete Protected entity-read projection must not hydrate candidate User entities.');
            })
            ->setAccount($principal)
            ->sort('uid', 'ASC');

        $ids = $this->scope->run($principal, static fn(): array => $query->execute());

        self::assertSame([1, 2], $ids);
    }

    #[Test]
    public function protected_candidate_projection_selects_only_the_declared_policy_input(): void
    {
        $principal = $this->profileViewer();
        $database = new QueryObservingDatabase($this->database);
        $query = $this->projectedQuery($database, $principal);

        self::assertSame([1, 2], $this->scope->run($principal, static fn(): array => $query->execute()));
        self::assertCount(1, $database->queries);
        self::assertStringContainsString("json_extract(\"user\"._data, '$.status')", $database->queries[0]);
        self::assertStringNotContainsString('$.mail', $database->queries[0]);
        self::assertStringNotContainsString('$.roles', $database->queries[0]);
        self::assertStringNotContainsString('$.name', $database->queries[0]);
    }

    #[Test]
    public function user_projection_uses_declared_structure_without_schema_introspection(): void
    {
        $principal = $this->profileViewer();
        $database = new QueryObservingDatabase($this->database, forbidFieldIntrospection: true);
        $query = new SqlEntityQuery(
            $this->entityType,
            $database,
            fieldRegistry: $this->fieldRegistry,
            fieldReadScope: $this->scope,
        )
            ->withAccessHandler($this->handler)
            ->withEntityLoader(static function (array $ids): array {
                self::fail('A complete Protected entity-read projection must not hydrate candidate User entities.');
            })
            ->setAccount($principal);

        self::assertSame([1, 2], $this->scope->run($principal, static fn(): array => $query->execute()));
        self::assertCount(1, $database->queries);
        self::assertStringContainsString("json_extract(\"user\"._data, '$.status')", $database->queries[0]);
        self::assertStringContainsString('"user"."uuid" AS "__waaseyaa_structure_uuid"', $database->queries[0]);
    }

    #[Test]
    public function user_projection_fails_closed_when_a_declared_structural_column_is_missing(): void
    {
        $this->database->schema()->dropField('user', 'uuid');
        $principal = $this->profileViewer();
        $query = $this->projectedQuery($this->database, $principal);

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->scope->run($principal, static fn(): array => $query->execute());
    }

    #[Test]
    public function protected_candidate_projection_fails_closed_when_a_candidate_row_disappears(): void
    {
        $principal = $this->profileViewer();
        $database = new QueryObservingDatabase(
            $this->database,
            fn(): int => $this->database->delete('user')->condition('uid', 3)->execute(),
        );
        $query = $this->projectedQuery($database, $principal);

        $this->expectException(ProtectedEntityReadProjectionException::class);
        $this->scope->run($principal, static fn(): array => $query->execute());
    }

    #[Test]
    public function protected_candidate_projection_fails_closed_when_its_layout_generation_changes(): void
    {
        $principal = $this->profileViewer();
        $database = new QueryObservingDatabase(
            $this->database,
            fn(): mixed => $this->fieldRegistry->registerCoreFields(
                'user',
                $this->entityType->getFieldDefinitions(),
            ),
        );
        $query = $this->projectedQuery($database, $principal);

        $this->expectException(StaleEntityReadLayout::class);
        $this->scope->run($principal, static fn(): array => $query->execute());
    }

    #[Test]
    public function protected_candidate_projection_chunks_large_candidate_sets_and_preserves_order(): void
    {
        for ($i = 4; $i <= 504; ++$i) {
            $this->database->insert('user')
                ->fields(['uuid', 'bundle', 'name', 'langcode', '_data'])
                ->values([
                    'user-' . $i,
                    'user',
                    'member-' . $i,
                    'en',
                    json_encode(['status' => 1], JSON_THROW_ON_ERROR),
                ])
                ->execute();
        }

        $principal = $this->profileViewer();
        $database = new QueryObservingDatabase($this->database);
        $query = $this->projectedQuery($database, $principal);
        $ids = $this->scope->run($principal, static fn(): array => $query->execute());

        self::assertCount(503, $ids);
        self::assertSame(1, $ids[0]);
        self::assertSame(504, $ids[array_key_last($ids)]);
        self::assertCount(2, $database->queries);
    }

    #[Test]
    public function protected_candidate_projection_rejects_an_unsupported_authorization_input_backend(): void
    {
        $definitions = $this->entityType->getFieldDefinitions();
        $definitions['status'] = $definitions['status']->storedIn(ReservedBackendIds::VECTOR);
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('user', $definitions);
        $principal = $this->profileViewer();
        $query = new SqlEntityQuery(
            $this->entityType,
            $this->database,
            fieldRegistry: $registry,
            fieldReadScope: $this->scope,
        )
            ->withAccessHandler($this->handler)
            ->withEntityLoader(static function (array $ids): array {
                self::fail('An unsupported Protected projection must stop before entity hydration.');
            })
            ->setAccount($principal);

        $this->expectException(ProtectedEntityReadProjectionException::class);
        $this->expectExceptionMessage('unsupported storage backend');
        $this->scope->run($principal, static fn(): array => $query->execute());
    }

    #[Test]
    public function projected_and_hydrated_policy_paths_have_identical_survivors(): void
    {
        $cases = [
            'profile viewer' => [$this->profileViewer(), [1, 2]],
            'administrator' => [new AuthorizationPrincipal(9, true, ['administrator'], ['administer users'], 'admin-v1'), [1, 2, 3]],
            'authenticated without permission' => [new AuthorizationPrincipal(9, true, ['authenticated'], [], 'member-v1'), []],
        ];

        foreach ($cases as $case => [$principal, $expected]) {
            self::assertInstanceOf(AuthorizationPrincipal::class, $principal);
            $projected = $this->queryWithHandler($this->handler, $principal, false);
            $hydrated = $this->queryWithHandler(
                new EntityAccessHandler([new HydratedOnlyUserAccessPolicy()]),
                $principal,
                true,
            );

            $projectedIds = $this->scope->run($principal, static fn(): array => $projected->execute());
            $hydratedIds = $this->scope->run($principal, static fn(): array => $hydrated->execute());

            self::assertSame($expected, $projectedIds, $case);
            self::assertSame($projectedIds, $hydratedIds, $case);
        }
    }

    #[Test]
    public function incomplete_projected_policy_metadata_stops_without_hydrated_fallback(): void
    {
        $principal = $this->profileViewer();
        $query = $this->queryWithHandler(
            new EntityAccessHandler([new IncompleteProjectedUserAccessPolicy()]),
            $principal,
            false,
        );

        $this->expectException(ProtectedEntityReadProjectionException::class);
        $this->expectExceptionMessage('reviewed policy input set does not match');
        $this->scope->run($principal, static fn(): array => $query->execute());
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

    private function profileViewer(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(
            1,
            true,
            ['authenticated'],
            ['access user profiles'],
            'viewer-claims-v1',
        );
    }

    private function projectedQuery(DatabaseInterface $database, AuthorizationPrincipal $principal): SqlEntityQuery
    {
        return new SqlEntityQuery(
            $this->entityType,
            $database,
            fieldRegistry: $this->fieldRegistry,
            fieldReadScope: $this->scope,
        )
            ->withAccessHandler($this->handler)
            ->withEntityLoader(static function (array $ids): array {
                self::fail('A complete Protected entity-read projection must not hydrate candidate User entities.');
            })
            ->setAccount($principal)
            ->sort('uid', 'ASC');
    }

    private function queryWithHandler(
        EntityAccessHandler $handler,
        AuthorizationPrincipal $principal,
        bool $allowHydration,
    ): SqlEntityQuery {
        return new SqlEntityQuery(
            $this->entityType,
            $this->database,
            fieldRegistry: $this->fieldRegistry,
            fieldReadScope: $this->scope,
        )
            ->withAccessHandler($handler)
            ->withEntityLoader(function (array $ids) use ($allowHydration): array {
                if (!$allowHydration) {
                    self::fail('An invalid or complete Protected projection must never enter hydrated fallback.');
                }

                $entities = [];
                foreach ($this->repository->findMany($ids) as $entity) {
                    $id = $entity->id();
                    if ($id !== null) {
                        $entities[$id] = $entity;
                    }
                }

                return $entities;
            })
            ->setAccount($principal)
            ->sort('uid', 'ASC');
    }
}

/** Test-only provider that forces the full sealed-entity evaluation path. */
final class HydratedOnlyUserAccessPolicy implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    private UserAccessPolicy $legacy;

    public function __construct()
    {
        $this->legacy = new UserAccessPolicy();
    }

    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface
    {
        return new HydratedOnlyUserEntityReadPolicy();
    }

    public function protectedFieldReadPolicy(): ?ProtectedFieldReadPolicyInterface
    {
        return $this->legacy->protectedFieldReadPolicy();
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return $this->legacy->access($entity, $operation, $account);
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return $this->legacy->createAccess($entityTypeId, $bundle, $account);
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $this->legacy->appliesTo($entityTypeId);
    }
}

/** Same immutable decision as UserEntityReadPolicy, without projection opt-in. */
final class HydratedOnlyUserEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        return new UserEntityReadPolicy()->access($principal, $structure, $subject, $operation);
    }
}

/** Test-only provider whose stale projection declaration omits the required status input. */
final class IncompleteProjectedUserAccessPolicy implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    private HydratedOnlyUserAccessPolicy $delegate;

    public function __construct()
    {
        $this->delegate = new HydratedOnlyUserAccessPolicy();
    }

    public function protectedEntityReadPolicy(): ProjectedProtectedEntityReadPolicyInterface
    {
        return new IncompleteProjectedUserEntityReadPolicy();
    }

    public function protectedFieldReadPolicy(): ?ProtectedFieldReadPolicyInterface
    {
        return $this->delegate->protectedFieldReadPolicy();
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return $this->delegate->access($entity, $operation, $account);
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return $this->delegate->createAccess($entityTypeId, $bundle, $account);
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $this->delegate->appliesTo($entityTypeId);
    }
}

final class IncompleteProjectedUserEntityReadPolicy implements ProjectedProtectedEntityReadPolicyInterface
{
    public function authorizationInputs(): array
    {
        return [];
    }

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        return new UserEntityReadPolicy()->access($principal, $structure, $subject, $operation);
    }
}

/** Test-only observation seam around the closed projection query. */
final class QueryObservingDatabase implements DatabaseInterface
{
    /** @var list<string> */
    public array $queries = [];

    /** @param (\Closure(): mixed)|null $beforeQuery */
    public function __construct(
        private readonly DatabaseInterface $inner,
        private readonly ?\Closure $beforeQuery = null,
        private readonly bool $forbidFieldIntrospection = false,
    ) {}

    public function select(string $table, string $alias = ''): \Waaseyaa\Database\SelectInterface
    {
        return $this->inner->select($table, $alias);
    }

    public function insert(string $table): \Waaseyaa\Database\InsertInterface
    {
        return $this->inner->insert($table);
    }

    public function update(string $table): \Waaseyaa\Database\UpdateInterface
    {
        return $this->inner->update($table);
    }

    public function delete(string $table): \Waaseyaa\Database\DeleteInterface
    {
        return $this->inner->delete($table);
    }

    public function schema(): \Waaseyaa\Database\SchemaInterface
    {
        $schema = $this->inner->schema();
        if (!$this->forbidFieldIntrospection) {
            return $schema;
        }

        return new class ($schema) implements \Waaseyaa\Database\SchemaInterface {
            public function __construct(private readonly \Waaseyaa\Database\SchemaInterface $inner) {}
            public function tableExists(string $table): bool
            {
                return $this->inner->tableExists($table);
            }
            public function fieldExists(string $table, string $field): bool
            {
                throw new \LogicException(sprintf('Unexpected schema introspection for %s.%s.', $table, $field));
            }
            public function createTable(string $name, array $spec): void
            {
                $this->inner->createTable($name, $spec);
            }
            public function dropTable(string $table): void
            {
                $this->inner->dropTable($table);
            }
            public function addField(string $table, string $field, array $spec): void
            {
                $this->inner->addField($table, $field, $spec);
            }
            public function dropField(string $table, string $field): void
            {
                $this->inner->dropField($table, $field);
            }
            public function addIndex(string $table, string $name, array $fields): void
            {
                $this->inner->addIndex($table, $name, $fields);
            }
            public function dropIndex(string $table, string $name): void
            {
                $this->inner->dropIndex($table, $name);
            }
            public function addUniqueKey(string $table, string $name, array $fields): void
            {
                $this->inner->addUniqueKey($table, $name, $fields);
            }
            public function addPrimaryKey(string $table, array $fields): void
            {
                $this->inner->addPrimaryKey($table, $fields);
            }
            public function listTableNames(): array
            {
                return $this->inner->listTableNames();
            }
        };
    }

    public function transaction(string $name = ''): \Waaseyaa\Database\TransactionInterface
    {
        return $this->inner->transaction($name);
    }

    public function query(string $sql, array $args = []): \Traversable
    {
        $this->queries[] = $sql;
        if ($this->beforeQuery !== null) {
            ($this->beforeQuery)();
        }

        return $this->inner->query($sql, $args);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->inner->quoteIdentifier($identifier);
    }
}
