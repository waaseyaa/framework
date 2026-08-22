<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Community\CommunityContext;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Log\Handler\ErrorLogHandler;
use Waaseyaa\Foundation\Log\LogManager;

#[CoversClass(EntityTypeManagerFactory::class)]
final class EntityTypeManagerFactoryTest extends TestCase
{
    private DBALDatabase $database;
    private SymfonyEventDispatcherAdapter $dispatcher;
    private FieldDefinitionRegistry $fieldRegistry;
    private LogManager $logger;
    private AccountFieldReadScope $fieldReadScope;

    protected function setUp(): void
    {
        $this->database     = DBALDatabase::createSqlite(':memory:');
        EntityMutationAuthoritySchema::ensure($this->database);
        $this->dispatcher   = new SymfonyEventDispatcherAdapter();
        $this->fieldRegistry = new FieldDefinitionRegistry();
        $this->logger       = new LogManager(new ErrorLogHandler());
        $this->fieldReadScope = new AccountFieldReadScope();
    }

    #[Test]
    public function build_returns_entity_type_manager(): void
    {
        $factory = new EntityTypeManagerFactory();

        $manager = $factory->build(
            database: $this->database,
            dispatcher: $this->dispatcher,
            fieldRegistry: $this->fieldRegistry,
            logger: $this->logger,
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn($def) => null,
            accountContextAttacher: static function (object $repo): void {},
            fieldReadScope: $this->fieldReadScope,
        );

        $this->assertInstanceOf(EntityTypeManager::class, $manager);
    }

    #[Test]
    public function build_wires_field_registry_into_manager(): void
    {
        $factory = new EntityTypeManagerFactory();

        $manager = $factory->build(
            database: $this->database,
            dispatcher: $this->dispatcher,
            fieldRegistry: $this->fieldRegistry,
            logger: $this->logger,
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn($def) => null,
            accountContextAttacher: static function (object $repo): void {},
            fieldReadScope: $this->fieldReadScope,
        );

        // The manager exposes the field registry it was given.
        $this->assertSame($this->fieldRegistry, $manager->getFieldRegistry());
    }

    #[Test]
    public function account_context_attacher_is_called_when_repository_is_created(): void
    {
        $attached = [];
        $factory  = new EntityTypeManagerFactory();

        $manager = $factory->build(
            database: $this->database,
            dispatcher: $this->dispatcher,
            fieldRegistry: $this->fieldRegistry,
            logger: $this->logger,
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn($def) => null,
            accountContextAttacher: static function (object $repo) use (&$attached): void {
                $attached[] = $repo;
            },
            fieldReadScope: $this->fieldReadScope,
        );

        // Register and retrieve a repository to trigger the factory closure.
        $manager->registerEntityType(new \Waaseyaa\Entity\EntityType(
            id: 'attach_test',
            label: 'Attach Test',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));
        new EntitySchemaSync($this->database, $this->fieldRegistry)->syncAll([
            $manager->getDefinition('attach_test'),
        ]);
        $manager->getRepository('attach_test');

        $this->assertCount(1, $attached, 'accountContextAttacher must be called once per repository build');
    }

    #[Test]
    public function community_score_resolver_is_called_when_repository_is_created(): void
    {
        $resolvedTypes = [];
        $factory       = new EntityTypeManagerFactory();

        $manager = $factory->build(
            database: $this->database,
            dispatcher: $this->dispatcher,
            fieldRegistry: $this->fieldRegistry,
            logger: $this->logger,
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static function (\Waaseyaa\Entity\EntityTypeInterface $def) use (&$resolvedTypes): ?object {
                $resolvedTypes[] = $def->id();

                return null;
            },
            accountContextAttacher: static function (object $repo): void {},
            fieldReadScope: $this->fieldReadScope,
        );

        $manager->registerEntityType(new \Waaseyaa\Entity\EntityType(
            id: 'scope_test',
            label: 'Scope Test',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));
        new EntitySchemaSync($this->database, $this->fieldRegistry)->syncAll([
            $manager->getDefinition('scope_test'),
        ]);
        $manager->getRepository('scope_test');

        $this->assertContains('scope_test', $resolvedTypes, 'communityScoreResolver must be called with the entity type definition');
    }

    #[Test]
    public function build_wires_the_same_community_scope_into_revision_storage(): void
    {
        $context = new CommunityContext();
        $context->set('community-a');
        $scope = new CommunityScope($context);
        $manager = new EntityTypeManagerFactory()->build(
            database: $this->database,
            dispatcher: $this->dispatcher,
            fieldRegistry: $this->fieldRegistry,
            logger: $this->logger,
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn() => $scope,
            accountContextAttacher: static function (object $repo): void {},
            fieldReadScope: $this->fieldReadScope,
        );
        $manager->registerEntityType(new EntityType(
            id: 'kernel_scoped_revisionable',
            label: 'Kernel scoped revisionable',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
            tenancy: ['scope' => EntityType::TENANCY_SCOPE_COMMUNITY],
        ));
        new EntitySchemaSync($this->database, $this->fieldRegistry)->syncAll([
            $manager->getDefinition('kernel_scoped_revisionable'),
        ]);
        $repository = $manager->getRepository('kernel_scoped_revisionable');
        $entity = new TestRevisionableEntity(values: [
            'id' => '1',
            'uuid' => 'kernel-scoped-a',
            'title' => 'Community A',
            'community_id' => 'community-a',
        ], entityTypeId: 'kernel_scoped_revisionable', entityKeys: [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
            'revision' => 'revision_id',
        ]);
        $entity->enforceIsNew();
        $repository->save($entity, validate: false);

        $context->set('community-b');

        self::assertNull($repository->find('1'));
        self::assertNull($repository->loadRevision('1', 1));
        self::assertSame([], $repository->listRevisions('1'));
    }

    #[Test]
    public function assert_registered_runtime_schemas_refuses_sql_backed_types_without_a_table(): void
    {
        $manager = $this->manager();
        $manager->registerEntityType(new EntityType(
            id: 'sql_widget',
            label: 'SQL widget',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));

        try {
            new EntityTypeManagerFactory()->assertRegisteredRuntimeSchemas(
                $this->database,
                $manager,
                $this->fieldRegistry,
                $this->logger,
            );
            self::fail('SQL-backed definitions must fail closed without a table.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB106', $exception->getMessage());
            self::assertStringContainsString('sql_widget', $exception->getMessage());
        }
    }

    #[Test]
    public function assert_registered_runtime_schemas_accepts_valid_custom_storage_without_sql_table(): void
    {
        $manager = $this->manager();
        $manager->registerEntityType(new EntityType(
            id: 'custom_remote',
            label: 'Custom remote',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            storageClass: CustomRemoteEntityStorage::class,
        ));

        new EntityTypeManagerFactory()->assertRegisteredRuntimeSchemas(
            $this->database,
            $manager,
            $this->fieldRegistry,
            $this->logger,
        );

        self::assertFalse($this->database->schema()->tableExists('custom_remote'));
    }

    #[Test]
    public function assert_registered_runtime_schemas_rejects_invalid_storage_class(): void
    {
        $manager = $this->manager();
        $manager->registerEntityType(new EntityType(
            id: 'broken_storage',
            label: 'Broken storage',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            storageClass: \stdClass::class,
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must implement');
        new EntityTypeManagerFactory()->assertRegisteredRuntimeSchemas(
            $this->database,
            $manager,
            $this->fieldRegistry,
            $this->logger,
        );
    }

    private function manager(): EntityTypeManager
    {
        return new EntityTypeManagerFactory()->build(
            database: $this->database,
            dispatcher: $this->dispatcher,
            fieldRegistry: $this->fieldRegistry,
            logger: $this->logger,
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn() => null,
            accountContextAttacher: static function (object $repo): void {},
            fieldReadScope: $this->fieldReadScope,
        );
    }
}

final class CustomRemoteEntityStorage implements EntityStorageInterface
{
    public function create(array $values = []): EntityInterface
    {
        throw new \BadMethodCallException('Custom remote storage is not exercised by schema assertion.');
    }

    public function load(int|string $id): ?EntityInterface
    {
        return null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        return null;
    }

    public function loadMultiple(array $ids = []): array
    {
        return [];
    }

    public function save(EntityInterface $entity): int
    {
        throw new \BadMethodCallException('Custom remote storage is not exercised by schema assertion.');
    }

    public function delete(array $entities): void {}

    public function getQuery(): EntityQueryInterface
    {
        return new class implements EntityQueryInterface {
            public function condition(string $field, mixed $value, string $operator = '='): static
            {
                return $this;
            }

            public function exists(string $field): static
            {
                return $this;
            }

            public function notExists(string $field): static
            {
                return $this;
            }

            public function sort(string $field, string $direction = 'ASC'): static
            {
                return $this;
            }

            public function range(int $offset, int $limit): static
            {
                return $this;
            }

            public function count(): static
            {
                return $this;
            }

            public function accessCheck(bool $check = true): static
            {
                return $this;
            }

            public function setAccount(?AccountInterface $account): static
            {
                return $this;
            }

            public function execute(): array
            {
                return [];
            }
        };
    }

    public function getEntityTypeId(): string
    {
        return 'custom_remote';
    }
}
