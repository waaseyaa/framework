<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Entity\Validation\RedactedInvalidValue;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldStorage;
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
            fieldTypes: $this->fieldRegistry->fieldTypeManager(),
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
            fieldTypes: $this->fieldRegistry->fieldTypeManager(),
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
            fieldTypes: $this->fieldRegistry->fieldTypeManager(),
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
            fieldTypes: $this->fieldRegistry->fieldTypeManager(),
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
            fieldTypes: $this->fieldRegistry->fieldTypeManager(),
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
                $this->fieldRegistry->fieldTypeManager(),
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
            $this->fieldRegistry->fieldTypeManager(),
        );

        self::assertFalse($this->database->schema()->tableExists('custom_remote'));
    }

    #[Test]
    public function repository_resolution_refuses_valid_custom_storage_before_sql_schema_inspection(): void
    {
        $manager = $this->manager();
        $manager->registerEntityType(new EntityType(
            id: 'custom_remote',
            label: 'Custom remote',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            storageClass: CustomRemoteEntityStorage::class,
        ));

        try {
            $manager->getRepository('custom_remote');
            self::fail('Custom-storage entity types must not resolve a Framework SQL repository.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('custom_remote', $exception->getMessage());
            self::assertStringContainsString('getStorage()', $exception->getMessage());
            self::assertStringNotContainsString('S1-DB106', $exception->getMessage());
        }
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
            $this->fieldRegistry->fieldTypeManager(),
        );
    }

    #[Test]
    public function build_wires_entity_reference_resolver_into_kernel_built_repositories(): void
    {
        EntityType::clearFromClassCache();

        $harness = $this->kernelReferenceValidationHarness();

        $harness['seedTarget']('1');
        $validNumeric = new KernelRefSubjectEntity(['id' => '1', 'author_id' => 1]);
        $validNumeric->enforceIsNew();
        self::assertSame(EntityConstants::SAVED_NEW, $harness['subjectRepository']->save($validNumeric));
        self::assertSame(1, $harness['rowCount']('kernel_ref_subject'));

        $missingNumeric = new KernelRefSubjectEntity(['id' => '2', 'author_id' => 404]);
        $missingNumeric->enforceIsNew();
        try {
            $harness['subjectRepository']->save($missingNumeric);
            self::fail('Missing numeric reference must be rejected before write.');
        } catch (EntityValidationException) {
        }
        self::assertSame(1, $harness['rowCount']('kernel_ref_subject'));

        $targetUuid = '3f2a1b4c-5d6e-4f80-9a1b-2c3d4e5f6a7b';
        $harness['seedTarget']('9', $targetUuid);
        $validUuid = new KernelRefSubjectEntity(['id' => '3', 'author_id' => $targetUuid]);
        $validUuid->enforceIsNew();
        self::assertSame(EntityConstants::SAVED_NEW, $harness['subjectRepository']->save($validUuid));
        self::assertSame(2, $harness['rowCount']('kernel_ref_subject'));

        $harness['seedTarget']('10');
        $harness['seedTarget']('11');
        $validMultiple = new KernelRefSubjectEntity(['id' => '4', 'tag_ids' => [10, 11]]);
        $validMultiple->enforceIsNew();
        self::assertSame(EntityConstants::SAVED_NEW, $harness['subjectRepository']->save($validMultiple));
        self::assertSame(3, $harness['rowCount']('kernel_ref_subject'));

        $invalidMultiple = new KernelRefSubjectEntity(['id' => '5', 'tag_ids' => [10, 404]]);
        $invalidMultiple->enforceIsNew();
        try {
            $harness['subjectRepository']->save($invalidMultiple);
            self::fail('Multiple-cardinality missing member must be rejected before write.');
        } catch (EntityValidationException $exception) {
            self::assertGreaterThan(0, $exception->violations->count());
        }
        self::assertSame(3, $harness['rowCount']('kernel_ref_subject'));

        $protectedMissing = new KernelProtectedRefSubjectEntity(['id' => '6', 'secret_author_id' => 404]);
        $protectedMissing->enforceIsNew();
        try {
            $harness['protectedRepository']->save($protectedMissing);
            self::fail('Protected reference must be rejected before write.');
        } catch (EntityValidationException $exception) {
            self::assertSame('secret_author_id', $exception->violations->get(0)->getPropertyPath());
            self::assertSame(
                'The non-Public field value is invalid.',
                $exception->violations->get(0)->getMessage(),
            );
            self::assertSame(RedactedInvalidValue::Value, $exception->violations->get(0)->getInvalidValue());
        }
        self::assertSame(0, $harness['rowCount']('kernel_protected_ref_subject'));
    }

    /**
     * @return array{
     *     manager: EntityTypeManager,
     *     targetRepository: EntityRepository,
     *     subjectRepository: EntityRepository,
     *     protectedRepository: EntityRepository,
     *     seedTarget: callable(string, ?string): void,
     *     rowCount: callable(string): int,
     * }
     */
    private function kernelReferenceValidationHarness(): array
    {
        $factory = new EntityTypeManagerFactory();
        $manager = $factory->build(
            database: $this->database,
            dispatcher: $this->dispatcher,
            fieldRegistry: $this->fieldRegistry,
            logger: $this->logger,
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn() => null,
            accountContextAttacher: static function (object $repo): void {},
            fieldReadScope: $this->fieldReadScope,
            fieldTypes: $this->fieldRegistry->fieldTypeManager(),
        );

        $targetType = new EntityType(
            id: 'kernel_ref_target',
            label: 'Kernel ref target',
            class: KernelRefTargetEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
        );
        $subjectType = new EntityType(
            id: 'kernel_ref_subject',
            label: 'Kernel ref subject',
            class: KernelRefSubjectEntity::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: [
                'author_id' => new FieldDefinition(
                    name: 'author_id',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => 'kernel_ref_target'],
                    targetEntityTypeId: 'kernel_ref_subject',
                ),
                'tag_ids' => new FieldDefinition(
                    name: 'tag_ids',
                    type: 'entity_reference',
                    cardinality: -1,
                    settings: ['target_entity_type_id' => 'kernel_ref_target'],
                    targetEntityTypeId: 'kernel_ref_subject',
                    stored: FieldStorage::Data,
                ),
            ],
        );
        $protectedSubjectType = new EntityType(
            id: 'kernel_protected_ref_subject',
            label: 'Kernel protected ref subject',
            class: KernelProtectedRefSubjectEntity::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: [
                'secret_author_id' => new FieldDefinition(
                    name: 'secret_author_id',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => 'kernel_ref_target'],
                    targetEntityTypeId: 'kernel_protected_ref_subject',
                    read: FieldReadLevel::Protected,
                ),
            ],
        );

        $manager->registerEntityType($targetType);
        $manager->registerEntityType($subjectType);
        $manager->registerEntityType($protectedSubjectType);
        new EntitySchemaSync($this->database, $this->fieldRegistry)->syncAll([
            $manager->getDefinition('kernel_ref_target'),
            $manager->getDefinition('kernel_ref_subject'),
            $manager->getDefinition('kernel_protected_ref_subject'),
        ]);

        $targetRepository = $manager->getRepository('kernel_ref_target');
        $subjectRepository = $manager->getRepository('kernel_ref_subject');
        $protectedRepository = $manager->getRepository('kernel_protected_ref_subject');

        $seedTarget = function (string $id, ?string $uuid = null) use ($targetRepository): void {
            $values = ['id' => $id];
            if ($uuid !== null) {
                $values['uuid'] = $uuid;
            }
            $target = new KernelRefTargetEntity($values);
            $target->enforceIsNew();
            $targetRepository->save($target, validate: false);
        };

        $rowCount = fn(string $table): int => (int) $this->database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $table,
        );

        return [
            'manager' => $manager,
            'targetRepository' => $targetRepository,
            'subjectRepository' => $subjectRepository,
            'protectedRepository' => $protectedRepository,
            'seedTarget' => $seedTarget,
            'rowCount' => $rowCount,
        ];
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
            fieldTypes: $this->fieldRegistry->fieldTypeManager(),
        );
    }
}

#[ContentEntityType(id: 'kernel_ref_target')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid')]
final class KernelRefTargetEntity extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $uuid = '';
}

#[ContentEntityType(id: 'kernel_ref_subject')]
#[ContentEntityKeys(id: 'id')]
final class KernelRefSubjectEntity extends ContentEntityBase
{
    #[Field(type: 'entity_reference', settings: ['target_entity_type_id' => 'kernel_ref_target'], read: FieldReadLevel::Public)]
    public int|string|null $author_id = null;

    #[Field(
        type: 'entity_reference',
        settings: ['target_entity_type_id' => 'kernel_ref_target'],
        read: FieldReadLevel::Public,
    )]
    public mixed $tag_ids = null;
}

#[ContentEntityType(id: 'kernel_protected_ref_subject')]
#[ContentEntityKeys(id: 'id')]
final class KernelProtectedRefSubjectEntity extends ContentEntityBase
{
    #[Field(
        type: 'entity_reference',
        settings: ['target_entity_type_id' => 'kernel_ref_target'],
        read: FieldReadLevel::Protected,
    )]
    public int|string|null $secret_author_id = null;
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
