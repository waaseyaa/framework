<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Repository\EntityIdentifierResolver;
use Waaseyaa\Entity\Validation\EntityReferenceExistenceConstraintBuilder;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Validation\DatabaseValidationReadLedger;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldStorage;

#[CoversClass(EntityRepository::class)]
#[CoversClass(V2EntityRepositoryFactory::class)]
final class EntityRepositoryEntityReferenceValidationTest extends TestCase
{
    private const TARGET_UUID = '3f2a1b4c-5d6e-4f80-9a1b-2c3d4e5f6a7b';

    /**
     * @return iterable<string, array{string}>
     */
    public static function primaryBackendProvider(): iterable
    {
        yield 'sql-blob' => [ReservedBackendIds::SQL_BLOB];
        yield 'sql-column' => [ReservedBackendIds::SQL_COLUMN];
    }

    #[Test]
    #[DataProvider('primaryBackendProvider')]
    public function activeValidationWithoutResolverRefusesBeforeDriverWrite(string $backend): void
    {
        $harness = $this->harness($backend, withResolver: false);

        $entity = new RefSubjectEntity(['id' => '1', 'author_id' => 99]);
        $entity->enforceIsNew();

        try {
            $harness->subjectRepository->save($entity);
            self::fail('Expected LogicException before storage write.');
        } catch (\LogicException $exception) {
            self::assertSame(
                EntityReferenceExistenceConstraintBuilder::missingResolverMessage('ref_subject'),
                $exception->getMessage(),
            );
        }

        self::assertSame(0, $harness->rowCount('ref_subject'));
    }

    #[Test]
    #[DataProvider('primaryBackendProvider')]
    public function validNumericReferencePersists(string $backend): void
    {
        $harness = $this->harness($backend);
        $harness->targetRepository->save((function () {
            $target = new RefTargetEntity(['id' => '7', 'uuid' => 'target-7']);
            $target->enforceIsNew();

            return $target;
        })(), validate: false);

        $entity = new RefSubjectEntity(['id' => '1', 'author_id' => 7]);
        $entity->enforceIsNew();

        self::assertSame(EntityConstants::SAVED_NEW, $harness->subjectRepository->save($entity));
        self::assertSame(1, $harness->rowCount('ref_subject'));
    }

    #[Test]
    #[DataProvider('primaryBackendProvider')]
    public function missingNumericReferenceIsRejectedBeforeWrite(string $backend): void
    {
        $harness = $this->harness($backend);

        $entity = new RefSubjectEntity(['id' => '1', 'author_id' => 404]);
        $entity->enforceIsNew();

        try {
            $harness->subjectRepository->save($entity);
            self::fail('Expected EntityValidationException.');
        } catch (EntityValidationException $exception) {
            self::assertSame('author_id', $exception->violations->get(0)->getPropertyPath());
        }

        self::assertSame(0, $harness->rowCount('ref_subject'));
    }

    #[Test]
    #[DataProvider('primaryBackendProvider')]
    public function uuidReferenceResolvesThroughCanonicalResolver(string $backend): void
    {
        $harness = $this->harness($backend);
        $harness->targetRepository->save((function () {
            $target = new RefTargetEntity(['id' => '9', 'uuid' => self::TARGET_UUID]);
            $target->enforceIsNew();

            return $target;
        })(), validate: false);

        $entity = new RefSubjectEntity(['id' => '1', 'author_id' => self::TARGET_UUID]);
        $entity->enforceIsNew();

        self::assertSame(EntityConstants::SAVED_NEW, $harness->subjectRepository->save($entity));
    }

    #[Test]
    public function multipleCardinalityValidatesEveryReference(): void
    {
        $harness = $this->harness(ReservedBackendIds::SQL_BLOB, multiple: true);
        foreach (['1', '2'] as $id) {
            $target = new RefTargetEntity(['id' => $id, 'uuid' => $id === '1' ? 'one' : 'two']);
            $target->enforceIsNew();
            $harness->targetRepository->save($target, validate: false);
        }

        $valid = new RefSubjectEntity(['id' => '10', 'tag_ids' => [1, 2]]);
        $valid->enforceIsNew();
        self::assertSame(EntityConstants::SAVED_NEW, $harness->subjectRepository->save($valid));

        $invalid = new RefSubjectEntity(['id' => '11', 'tag_ids' => [1, 99]]);
        $invalid->enforceIsNew();
        $this->expectException(EntityValidationException::class);
        $harness->subjectRepository->save($invalid);
    }

    #[Test]
    #[DataProvider('primaryBackendProvider')]
    public function optionalNullAndEmptyReferencesRemainValid(string $backend): void
    {
        $harness = $this->harness($backend);

        foreach ([null, ''] as $value) {
            $entity = new RefSubjectEntity(['id' => (string) random_int(100, 199), 'author_id' => $value]);
            $entity->enforceIsNew();
            self::assertSame(EntityConstants::SAVED_NEW, $harness->subjectRepository->save($entity));
        }

        if ($backend === ReservedBackendIds::SQL_BLOB) {
            $entity = new RefSubjectEntity(['id' => (string) random_int(100, 199), 'author_id' => []]);
            $entity->enforceIsNew();
            self::assertSame(EntityConstants::SAVED_NEW, $harness->subjectRepository->save($entity));
        }
    }

    #[Test]
    #[DataProvider('primaryBackendProvider')]
    public function protectedReferenceFieldUsesClosedValidationReader(string $backend): void
    {
        $harness = $this->harness($backend, protectedReference: true);

        $entity = new ProtectedRefSubjectEntity(['id' => '1', 'secret_author_id' => 404]);
        $entity->enforceIsNew();

        try {
            $harness->subjectRepository->save($entity);
            self::fail('Expected EntityValidationException.');
        } catch (EntityValidationException $exception) {
            self::assertSame('secret_author_id', $exception->violations->get(0)->getPropertyPath());
            self::assertSame(
                'The non-Public field value is invalid.',
                $exception->violations->get(0)->getMessage(),
            );
        }
    }

    #[Test]
    #[DataProvider('primaryBackendProvider')]
    public function saveManyValidationFailureRollsBackEntireBatch(string $backend): void
    {
        $harness = $this->harness($backend);
        $target = new RefTargetEntity(['id' => '5', 'uuid' => 'five']);
        $target->enforceIsNew();
        $harness->targetRepository->save($target, validate: false);

        $valid = new RefSubjectEntity(['id' => '20', 'author_id' => 5]);
        $valid->enforceIsNew();
        $invalid = new RefSubjectEntity(['id' => '21', 'author_id' => 999]);
        $invalid->enforceIsNew();

        try {
            $harness->subjectRepository->saveMany([$valid, $invalid]);
            self::fail('Expected EntityValidationException.');
        } catch (EntityValidationException) {
        }

        self::assertSame(0, $harness->rowCount('ref_subject'));
    }

    #[Test]
    #[DataProvider('primaryBackendProvider')]
    public function validateFalseBypassesReferenceExistenceChecks(string $backend): void
    {
        $harness = $this->harness($backend);

        $entity = new RefSubjectEntity(['id' => '1', 'author_id' => 404]);
        $entity->enforceIsNew();

        self::assertSame(
            EntityConstants::SAVED_NEW,
            $harness->subjectRepository->save($entity, validate: false),
        );
        self::assertSame(1, $harness->rowCount('ref_subject'));
    }

    #[Test]
    public function malformedTargetMetadataFailsClosedOnActiveValidationPath(): void
    {
        $harness = $this->harness(ReservedBackendIds::SQL_BLOB, referenceTargetTypeId: ['ref_target']);

        $entity = new RefSubjectEntity(['id' => '1', 'author_id' => 1]);
        $entity->enforceIsNew();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('author_id');
        $harness->subjectRepository->save($entity);
    }

    private function harness(
        string $backend,
        bool $withResolver = true,
        bool $multiple = false,
        bool $protectedReference = false,
        string $targetType = 'ref_target',
        mixed $referenceTargetTypeId = null,
    ): ReferenceValidationHarness {
        EntityType::clearFromClassCache();

        $database = DBALDatabase::createSqlite(':memory:');
        EntityMutationAuthoritySchema::ensure($database);
        $dispatcher = new EventDispatcher();
        $fieldRegistry = new FieldDefinitionRegistry();

        $targetTypeDef = new EntityType(
            id: $targetType,
            label: 'Reference target',
            class: RefTargetEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label', 'bundle' => 'bundle', 'langcode' => 'langcode'],
            primaryStorageBackend: $backend,
        );

        $subjectEntityTypeId = $protectedReference ? 'protected_ref_subject' : 'ref_subject';
        $resolvedReferenceTarget = $referenceTargetTypeId ?? $targetType;
        $subjectFields = $protectedReference
            ? [
                'secret_author_id' => new FieldDefinition(
                    name: 'secret_author_id',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => $resolvedReferenceTarget],
                    read: FieldReadLevel::Protected,
                ),
            ]
            : ($multiple
            ? [
                'tag_ids' => new FieldDefinition(
                    name: 'tag_ids',
                    type: 'entity_reference',
                    cardinality: -1,
                    settings: ['target_entity_type_id' => $resolvedReferenceTarget],
                    stored: $backend === ReservedBackendIds::SQL_COLUMN ? FieldStorage::Data : FieldStorage::Column,
                ),
            ]
            : [
                'author_id' => new FieldDefinition(
                    name: 'author_id',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => $resolvedReferenceTarget],
                ),
            ]);
        $registryFields = array_map(
            static fn(FieldDefinition $definition): array => [
                'type' => $definition->getType(),
                'cardinality' => $definition->getCardinality(),
                'settings' => $definition->getSettings(),
                'read' => $definition->getReadLevel(),
                'stored' => $definition->getStored(),
            ],
            $subjectFields,
        );

        $subjectTypeDef = new EntityType(
            id: $subjectEntityTypeId,
            label: 'Reference subject',
            class: $protectedReference ? ProtectedRefSubjectEntity::class : RefSubjectEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label', 'bundle' => 'bundle', 'langcode' => 'langcode'],
            _fieldDefinitions: $subjectFields,
            primaryStorageBackend: $backend,
        );

        $fieldRegistry->registerCoreFields($targetTypeDef->id(), $targetTypeDef->getFieldDefinitions());
        $fieldRegistry->registerCoreFields($subjectTypeDef->id(), $registryFields);

        $validator = EntityValidator::createDefault(new DatabaseValidationReadLedger($database));

        new EntitySchemaSync($database, $fieldRegistry)->syncAll([$targetTypeDef, $subjectTypeDef]);

        $manager = null;
        $manager = new EntityTypeManager(
            $dispatcher,
            null,
            static function (string $typeId, EntityType $type) use (
                $database,
                $dispatcher,
                $fieldRegistry,
                $validator,
                $withResolver,
                &$manager,
            ): EntityRepository {
                $driver = new SqlStorageDriver(
                    new SingleConnectionResolver($database),
                    fieldRegistry: $fieldRegistry,
                );
                new SqlSchemaHandler($type, $database, $fieldRegistry)->ensureTable();

                $resolver = $withResolver && $manager instanceof EntityTypeManager
                    ? new EntityIdentifierResolver($manager)
                    : null;

                return V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $type,
                    $driver,
                    $dispatcher,
                    database: $database,
                    validator: $validator,
                    fieldRegistry: null,
                    entityReferenceResolver: $resolver,
                );
            },
            null,
        );
        $manager->registerEntityType($targetTypeDef);
        $manager->registerEntityType($subjectTypeDef);

        return new ReferenceValidationHarness(
            $database,
            $manager->getRepository($targetTypeDef->id()),
            $manager->getRepository($subjectTypeDef->id()),
        );
    }
}

final class ReferenceValidationHarness
{
    public function __construct(
        private readonly DBALDatabase $database,
        public readonly EntityRepository $targetRepository,
        public readonly EntityRepository $subjectRepository,
    ) {}

    public function rowCount(string $table): int
    {
        return (int) $this->database->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}

#[ContentEntityType(id: 'ref_target')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label', bundle: 'bundle', langcode: 'langcode')]
final class RefTargetEntity extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $label;
}

#[ContentEntityType(id: 'ref_subject')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label', bundle: 'bundle', langcode: 'langcode')]
final class RefSubjectEntity extends ContentEntityBase
{
    #[Field(type: 'entity_reference', settings: ['target_entity_type_id' => 'ref_target'], read: FieldReadLevel::Public)]
    public int|string|null|array $author_id = null;

    #[Field(
        type: 'entity_reference',
        settings: ['target_entity_type_id' => 'ref_target'],
        read: FieldReadLevel::Public,
    )]
    public mixed $tag_ids = null;
}

#[ContentEntityType(id: 'protected_ref_subject')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label', bundle: 'bundle', langcode: 'langcode')]
final class ProtectedRefSubjectEntity extends ContentEntityBase
{
    #[Field(
        type: 'entity_reference',
        settings: ['target_entity_type_id' => 'ref_target'],
        read: FieldReadLevel::Protected,
    )]
    public int|string|null $secret_author_id = null;
}
