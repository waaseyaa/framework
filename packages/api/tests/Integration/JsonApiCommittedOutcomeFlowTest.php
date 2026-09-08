<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Api\Tests\Support\AccountScopedJsonApiController;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Exception\TransactionCompletionException;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\Router\JsonApiRouter;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * Production composition for #2999 JSON:API committed-but-side-effects-failed
 * outcomes: real SQLite, EntityRepository, UnitOfWork completion drain, and
 * injected post-commit listener failures — not a repository mock that merely
 * throws.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiCommittedOutcomeFlowTest extends TestCase
{
    private const HOSTILE_MESSAGE = 'HOSTILE: secret payload leak attempt 0xDEADBEEF';

    #[Test]
    public function post_returns_committed_side_effects_failed_while_row_and_assigned_id_are_durable(): void
    {
        $harness = $this->bootHarness(postCommitFailure: true);

        $doc = $harness['controller']->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'Committed create', 'type' => 'article'],
            ],
        ]);

        $this->assertCommittedOutcomeDocument($doc, 'create', expectEntityId: true);
        $rows = $this->articleRows($harness['database']);
        self::assertCount(1, $rows);
        self::assertSame((string) $doc->errors[0]->meta['resource_id'], (string) $rows[0]['id']);
        self::assertSame('Committed create', $this->storedTitle($harness['database'], (string) $rows[0]['id']));
    }

    #[Test]
    public function patch_returns_committed_side_effects_failed_while_update_is_durable(): void
    {
        $harness = $this->bootHarness(postCommitFailure: false);
        $entity = new TestEntity(['title' => 'Before patch', 'type' => 'article']);
        $entity->enforceIsNew();
        $harness['repository']->save($entity);
        $entityId = (string) $entity->id();

        $harness['dispatcher']->addListener(
            EntityEvents::POST_SAVE->value,
            static function (): never {
                throw new \RuntimeException(self::HOSTILE_MESSAGE);
            },
        );

        $doc = $harness['controller']->update('article', $entityId, [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'After patch'],
            ],
        ]);

        $this->assertCommittedOutcomeDocument($doc, 'update', $entityId);
        self::assertSame('After patch', $this->storedTitle($harness['database'], $entityId));
    }

    #[Test]
    public function delete_returns_committed_side_effects_failed_while_row_is_absent(): void
    {
        $harness = $this->bootHarness(postCommitFailure: false);
        $entity = new TestEntity(['title' => 'To delete', 'type' => 'article']);
        $entity->enforceIsNew();
        $harness['repository']->save($entity);
        $entityId = (string) $entity->id();

        $harness['dispatcher']->addListener(
            EntityEvents::POST_DELETE->value,
            static function (): never {
                throw new \RuntimeException(self::HOSTILE_MESSAGE);
            },
        );

        $doc = $harness['jsonApiController']->destroy('article', $entityId);

        $this->assertCommittedOutcomeDocument($doc, 'delete', $entityId);
        self::assertSame([], $this->articleRows($harness['database']));
    }

    #[Test]
    public function hostile_callback_message_is_absent_from_error_envelope(): void
    {
        $harness = $this->bootHarness(postCommitFailure: true);

        $doc = $harness['controller']->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'Leak probe', 'type' => 'article'],
            ],
        ]);

        $wire = json_encode($doc->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::HOSTILE_MESSAGE, $wire);
        self::assertStringNotContainsString('RuntimeException', $wire);
        self::assertStringNotContainsString('Leak probe', $wire);
    }

    #[Test]
    public function pre_save_failure_rolls_back_and_does_not_use_committed_side_effects_failed(): void
    {
        $harness = $this->bootHarness(postCommitFailure: false);
        $harness['dispatcher']->addListener(
            EntityEvents::PRE_SAVE->value,
            static function (): never {
                throw new \RuntimeException('PRE_SAVE refused before commit');
            },
        );

        try {
            $harness['controller']->store('article', [
                'data' => [
                    'type' => 'article',
                    'attributes' => ['title' => 'Must not persist', 'type' => 'article'],
                ],
            ]);
            self::fail('PRE_SAVE failure must propagate before a committed-outcome envelope.');
        } catch (\RuntimeException $failure) {
            self::assertSame('PRE_SAVE refused before commit', $failure->getMessage());
        }

        self::assertSame([], $this->articleRows($harness['database']));
    }

    #[Test]
    public function pre_save_completion_shaped_exception_rolls_back_and_is_not_reported_committed(): void
    {
        $harness = $this->bootHarness(postCommitFailure: false);
        $harness['dispatcher']->addListener(
            EntityEvents::PRE_SAVE->value,
            static function (): never {
                throw new TransactionCompletionException([
                    new \RuntimeException('A different transaction completed with a failed effect.'),
                ]);
            },
        );

        $document = null;
        $failure = null;
        try {
            $document = $harness['controller']->store('article', [
                'data' => [
                    'type' => 'article',
                    'attributes' => ['title' => 'Must roll back', 'type' => 'article'],
                ],
            ]);
            self::fail('Pre-commit completion-shaped failure must not return a committed-outcome document.');
        } catch (\Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull($document);
        self::assertSame([], $this->articleRows($harness['database']));
        self::assertInstanceOf(TransactionCompletionException::class, $failure);
        self::assertSame(
            'A different transaction completed with a failed effect.',
            $failure->getPrevious()?->getMessage(),
        );
    }

    #[Test]
    public function independent_inner_pre_save_write_stays_durable_while_outer_create_rolls_back_without_committed_code(): void
    {
        $pair = $this->bootTwoConnectionHarness();
        $pair['outerDispatcher']->addListener(
            EntityEvents::PRE_SAVE->value,
            static function (EntityEvent $event) use ($pair): void {
                if ($event->entity->getEntityTypeId() !== 'article') {
                    return;
                }
                $inner = new TestEntity(
                    ['title' => 'Inner durable note', 'type' => 'note'],
                    entityTypeId: 'note',
                    entityKeys: TestEntity::definitionKeys(),
                );
                $inner->enforceIsNew();
                $pair['innerRepository']->save($inner);
            },
        );
        $pair['innerDispatcher']->addListener(
            EntityEvents::POST_SAVE->value,
            static function (): never {
                throw new \RuntimeException(self::HOSTILE_MESSAGE);
            },
        );

        $document = null;
        $failure = null;
        try {
            $document = $pair['controller']->store('article', [
                'data' => [
                    'type' => 'article',
                    'attributes' => ['title' => 'Outer must roll back', 'type' => 'article'],
                ],
            ]);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull($document, 'Outer create must not return a committed-outcome document.');
        self::assertInstanceOf(TransactionCompletionException::class, $failure);
        self::assertSame([], $this->tableRows($pair['outerDatabase'], 'article'));
        self::assertCount(1, $this->tableRows($pair['innerDatabase'], 'note'));
        self::assertSame(
            'Inner durable note',
            $this->storedColumn($pair['innerDatabase'], 'note', 'title'),
        );
    }

    #[Test]
    public function independent_inner_pre_delete_write_stays_durable_while_outer_delete_rolls_back_without_committed_code(): void
    {
        $pair = $this->bootTwoConnectionHarness();
        $entity = new TestEntity(['title' => 'Outer survivor', 'type' => 'article']);
        $entity->enforceIsNew();
        $pair['outerRepository']->save($entity);
        $entityId = (string) $entity->id();
        self::assertCount(1, $this->tableRows($pair['outerDatabase'], 'article'));

        $pair['outerDispatcher']->addListener(
            EntityEvents::PRE_DELETE->value,
            static function (EntityEvent $event) use ($pair): void {
                if ($event->entity->getEntityTypeId() !== 'article') {
                    return;
                }
                $inner = new TestEntity(
                    ['title' => 'Inner durable from delete path', 'type' => 'note'],
                    entityTypeId: 'note',
                    entityKeys: TestEntity::definitionKeys(),
                );
                $inner->enforceIsNew();
                $pair['innerRepository']->save($inner);
            },
        );
        $pair['innerDispatcher']->addListener(
            EntityEvents::POST_SAVE->value,
            static function (): never {
                throw new \RuntimeException(self::HOSTILE_MESSAGE);
            },
        );

        $document = null;
        $failure = null;
        try {
            $document = $pair['jsonApiController']->destroy('article', $entityId);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull($document, 'Outer delete must not return a committed-outcome document.');
        self::assertInstanceOf(TransactionCompletionException::class, $failure);
        self::assertCount(1, $this->tableRows($pair['outerDatabase'], 'article'));
        self::assertSame('Outer survivor', $this->storedTitle($pair['outerDatabase'], $entityId));
        self::assertCount(1, $this->tableRows($pair['innerDatabase'], 'note'));
    }

    #[Test]
    public function expectation_stated_patch_returns_committed_side_effects_failed_while_update_is_durable(): void
    {
        $harness = $this->bootRevisionableHarness();
        $entity = new TestRevisionableEntity(values: [
            'title' => 'Before expectation patch',
            'id' => '1',
            'uuid' => 'committed-outcome-rev-1',
        ]);
        $entity->enforceIsNew();
        $harness['repository']->save($entity);
        self::assertSame(1, $entity->getRevisionId());

        $harness['dispatcher']->addListener(
            EntityEvents::POST_SAVE->value,
            static function (): never {
                throw new \RuntimeException(self::HOSTILE_MESSAGE);
            },
        );

        $doc = $harness['controller']->update('test_revisionable', '1', [
            'data' => [
                'type' => 'test_revisionable',
                'attributes' => ['title' => 'After expectation patch'],
                'meta' => ['expected_revision_id' => 1],
            ],
        ]);

        $this->assertCommittedOutcomeDocument(
            $doc,
            'update',
            '1',
            resourceType: 'test_revisionable',
        );
        $reloaded = $harness['repository']->find('1');
        self::assertInstanceOf(TestRevisionableEntity::class, $reloaded);
        self::assertSame('After expectation patch', $reloaded->label());
        self::assertGreaterThan(1, (int) $reloaded->getRevisionId());
    }

    #[Test]
    public function controller_dispatcher_preserves_committed_outcome_http_envelope(): void
    {
        $harness = $this->bootHarness(postCommitFailure: true);
        $router = new WaaseyaaRouter();
        new JsonApiRouteProvider($harness['entityTypeManager'])->registerRoutes($router);
        $match = $router->match('/api/article');
        $body = [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'HTTP create', 'type' => 'article'],
            ],
        ];
        $request = Request::create(
            '/api/article',
            'POST',
            server: ['CONTENT_TYPE' => 'application/vnd.api+json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->attributes->add($match);
        $request->attributes->set('_account', $harness['account']);
        $request->attributes->set('_parsed_body', $body);
        $request->attributes->set('_broadcast_storage', new BroadcastStorage($harness['database']));

        $response = new ControllerDispatcher([
            new JsonApiRouter(
                $harness['entityTypeManager'],
                $harness['accessHandler'],
            ),
        ])->dispatch($request);

        $document = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('500', $document['errors'][0]['status']);
        self::assertSame(JsonApiController::COMMITTED_SIDE_EFFECTS_FAILED_CODE, $document['errors'][0]['code']);
        self::assertTrue($document['errors'][0]['meta']['committed']);
        self::assertSame('create', $document['errors'][0]['meta']['operation']);
        self::assertSame('article', $document['errors'][0]['meta']['resource_type']);
        self::assertIsString($document['errors'][0]['meta']['resource_id']);
        self::assertStringNotContainsString(self::HOSTILE_MESSAGE, (string) $response->getContent());
        self::assertCount(1, $this->articleRows($harness['database']));
    }

    /**
     * @param 'create'|'update'|'delete' $operation
     */
    private function assertCommittedOutcomeDocument(
        \Waaseyaa\Api\JsonApiDocument $document,
        string $operation,
        ?string $expectedEntityId = null,
        bool $expectEntityId = false,
        string $resourceType = 'article',
    ): void {
        self::assertNull($document->data);
        self::assertNotSame([], $document->errors);
        self::assertSame(500, $document->statusCode);

        $error = $document->errors[0];
        self::assertSame('500', $error->status);
        self::assertSame(JsonApiController::COMMITTED_SIDE_EFFECTS_FAILED_CODE, $error->code);
        self::assertSame('Committed mutation side effects failed', $error->title);
        self::assertStringContainsString('Do not retry the same request', $error->detail);
        self::assertTrue($error->meta['committed']);
        self::assertSame($operation, $error->meta['operation']);
        self::assertSame($resourceType, $error->meta['resource_type']);

        if ($expectedEntityId !== null) {
            self::assertSame($expectedEntityId, $error->meta['resource_id']);
        } elseif ($expectEntityId) {
            self::assertIsString($error->meta['resource_id']);
            self::assertNotSame('', $error->meta['resource_id']);
        }
    }

    /**
     * @return array{
     *     database: DBALDatabase,
     *     dispatcher: SymfonyEventDispatcherAdapter,
     *     entityTypeManager: EntityTypeManager,
     *     repository: EntityRepository,
     *     accessHandler: EntityAccessHandler,
     *     account: AuthorizationPrincipal,
     *     controller: AccountScopedJsonApiController,
     *     jsonApiController: JsonApiController,
     * }
     */
    private function bootHarness(bool $postCommitFailure): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $database = DBALDatabase::createSqlite();

        if ($postCommitFailure) {
            $dispatcher->addListener(
                EntityEvents::POST_SAVE->value,
                static function (): never {
                    throw new \RuntimeException(self::HOSTILE_MESSAGE);
                },
            );
        }

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $database): EntityRepositoryInterface {
            self::assertSame('article', $entityTypeId);
            new SqlSchemaHandler($definition, $database)->ensureTable();
            $resolver = new SingleConnectionResolver($database);

            return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $definition,
                new SqlStorageDriver($resolver, $definition->getKeys()['id']),
                $dispatcher,
                null,
                $database,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        /** @var EntityRepository $repository */
        $repository = $entityTypeManager->getRepository('article');
        RuntimeSchemaMigrations::broadcast($database);
        $accessHandler = new EntityAccessHandler([$this->allowAllPolicy()]);
        $account = new AuthorizationPrincipal(42, true, ['administrator'], [], 'committed-outcome-test');
        $jsonApiController = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            $accessHandler,
            $account,
        );
        $controller = new AccountScopedJsonApiController($jsonApiController, $accessHandler, $account);

        return [
            'database' => $database,
            'dispatcher' => $dispatcher,
            'entityTypeManager' => $entityTypeManager,
            'repository' => $repository,
            'accessHandler' => $accessHandler,
            'account' => $account,
            'controller' => $controller,
            'jsonApiController' => $jsonApiController,
        ];
    }

    /**
     * Two independent SQLite connections / UnitOfWork boundaries so an inner
     * repository write during outer PRE_SAVE or PRE_DELETE can commit while
     * the outer mutation still rolls back (#2999 finding 1).
     *
     * @return array{
     *     outerDatabase: DBALDatabase,
     *     innerDatabase: DBALDatabase,
     *     outerDispatcher: SymfonyEventDispatcherAdapter,
     *     innerDispatcher: SymfonyEventDispatcherAdapter,
     *     outerRepository: EntityRepository,
     *     innerRepository: EntityRepository,
     *     controller: AccountScopedJsonApiController,
     *     jsonApiController: JsonApiController,
     * }
     */
    private function bootTwoConnectionHarness(): array
    {
        $outerDispatcher = new SymfonyEventDispatcherAdapter();
        $innerDispatcher = new SymfonyEventDispatcherAdapter();
        $outerDatabase = DBALDatabase::createSqlite();
        $innerDatabase = DBALDatabase::createSqlite();

        $noteDefinition = new EntityType(
            id: 'note',
            label: 'Note',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        );
        new SqlSchemaHandler($noteDefinition, $innerDatabase)->ensureTable();
        RuntimeSchemaMigrations::broadcast($innerDatabase);
        $innerRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $noteDefinition,
            new SqlStorageDriver(new SingleConnectionResolver($innerDatabase), $noteDefinition->getKeys()['id']),
            $innerDispatcher,
            null,
            $innerDatabase,
        );

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($outerDispatcher, $outerDatabase): EntityRepositoryInterface {
            self::assertSame('article', $entityTypeId);
            new SqlSchemaHandler($definition, $outerDatabase)->ensureTable();

            return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $definition,
                new SqlStorageDriver(new SingleConnectionResolver($outerDatabase), $definition->getKeys()['id']),
                $outerDispatcher,
                null,
                $outerDatabase,
            );
        };

        $entityTypeManager = new EntityTypeManager($outerDispatcher, null, $repositoryFactory);
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        /** @var EntityRepository $outerRepository */
        $outerRepository = $entityTypeManager->getRepository('article');
        RuntimeSchemaMigrations::broadcast($outerDatabase);
        $accessHandler = new EntityAccessHandler([$this->allowAllPolicy()]);
        $account = new AuthorizationPrincipal(42, true, ['administrator'], [], 'committed-outcome-two-conn');
        $jsonApiController = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            $accessHandler,
            $account,
        );
        $controller = new AccountScopedJsonApiController($jsonApiController, $accessHandler, $account);

        return [
            'outerDatabase' => $outerDatabase,
            'innerDatabase' => $innerDatabase,
            'outerDispatcher' => $outerDispatcher,
            'innerDispatcher' => $innerDispatcher,
            'outerRepository' => $outerRepository,
            'innerRepository' => $innerRepository,
            'controller' => $controller,
            'jsonApiController' => $jsonApiController,
        ];
    }

    /**
     * @return array{
     *     database: DBALDatabase,
     *     dispatcher: SymfonyEventDispatcherAdapter,
     *     repository: EntityRepository,
     *     controller: AccountScopedJsonApiController,
     * }
     */
    private function bootRevisionableHarness(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $database = DBALDatabase::createSqlite();
        $keys = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $database): EntityRepositoryInterface {
            self::assertSame('test_revisionable', $entityTypeId);
            $handler = new SqlSchemaHandler($definition, $database);
            $handler->ensureTable();
            $handler->ensureRevisionTable();
            $resolver = new SingleConnectionResolver($database);

            return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $definition,
                new SqlStorageDriver($resolver, $definition->getKeys()['id']),
                $dispatcher,
                new RevisionableStorageDriver($resolver, $definition),
                $database,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: $keys,
            revisionable: true,
            revisionDefault: true,
            api: true,
        ));

        /** @var EntityRepository $repository */
        $repository = $entityTypeManager->getRepository('test_revisionable');
        RuntimeSchemaMigrations::broadcast($database);
        $accessHandler = new EntityAccessHandler([$this->allowAllPolicy('test_revisionable')]);
        $account = new AuthorizationPrincipal(42, true, ['administrator'], [], 'committed-outcome-rev');
        $jsonApiController = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
            $accessHandler,
            $account,
        );

        return [
            'database' => $database,
            'dispatcher' => $dispatcher,
            'repository' => $repository,
            'controller' => new AccountScopedJsonApiController($jsonApiController, $accessHandler, $account),
        ];
    }

    private function allowAllPolicy(string $entityTypeId = 'article'): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class ($entityTypeId) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(private readonly string $entityTypeId) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articleRows(DBALDatabase $database): array
    {
        return $this->tableRows($database, 'article');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tableRows(DBALDatabase $database, string $table): array
    {
        return iterator_to_array($database->select($table)->fields($table)->execute());
    }

    private function storedTitle(DBALDatabase $database, string $id): string
    {
        return $this->storedColumn($database, 'article', 'title', $id);
    }

    private function storedColumn(DBALDatabase $database, string $table, string $column, ?string $id = null): string
    {
        if ($id === null) {
            $row = $database->getConnection()->fetchAssociative(
                sprintf('SELECT %s FROM %s LIMIT 1', $column, $table),
            );
        } else {
            $row = $database->getConnection()->fetchAssociative(
                sprintf('SELECT %s FROM %s WHERE id = ?', $column, $table),
                [$id],
            );
        }
        self::assertIsArray($row, 'Stored row must exist.');

        return (string) ($row[$column] ?? '');
    }
}
