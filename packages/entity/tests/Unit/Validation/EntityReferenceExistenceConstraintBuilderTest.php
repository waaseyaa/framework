<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityIdentifierResolver;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Entity\Tests\Unit\TestEntity;
use Waaseyaa\Entity\Tests\Unit\Validation\Fixture\FieldableEntityDouble;
use Waaseyaa\Entity\Validation\EntityReferenceExistenceConstraintBuilder;
use Waaseyaa\Entity\Validation\EntityTypeValidationConstraints;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Validation\Constraint\EntityExists;

#[CoversClass(EntityReferenceExistenceConstraintBuilder::class)]
final class EntityReferenceExistenceConstraintBuilderTest extends TestCase
{
    #[Test]
    public function definitionsRequireReferenceResolverDetectsEntityReferenceFields(): void
    {
        self::assertTrue(EntityReferenceExistenceConstraintBuilder::definitionsRequireReferenceResolver([
            'author_id' => new FieldDefinition(
                name: 'author_id',
                type: 'entity_reference',
                settings: ['target_entity_type_id' => 'user'],
            ),
        ]));
        self::assertFalse(EntityReferenceExistenceConstraintBuilder::definitionsRequireReferenceResolver([
            'title' => new FieldDefinition(name: 'title', type: 'string'),
        ]));
    }

    #[Test]
    public function missingTargetMetadataFailsClosedWhenBuildingExistenceConstraints(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('author_id');

        EntityReferenceExistenceConstraintBuilder::existenceConstraints(
            'author_id',
            new FieldDefinition(name: 'author_id', type: 'entity_reference'),
            $this->resolverForType('user'),
        );
    }

    #[Test]
    #[DataProvider('registeredTargetEntityTypeIdProvider')]
    public function registeredTargetEntityTypeIdIsPreservedAsCanonicalResolverInput(string $target): void
    {
        $constraints = EntityReferenceExistenceConstraintBuilder::existenceConstraints(
            'related_id',
            new FieldDefinition(
                name: 'related_id',
                type: 'entity_reference',
                settings: ['target_entity_type_id' => $target],
            ),
            $this->resolverForType($target),
        );

        self::assertCount(1, $constraints);
        self::assertInstanceOf(EntityExists::class, $constraints[0]);
        self::assertSame($target, $constraints[0]->entityTypeId);
    }

    /** @return iterable<string, array{string}> */
    public static function registeredTargetEntityTypeIdProvider(): iterable
    {
        yield 'Unicode authored type' => ['anishinaabe_ᐊᓂᔑ'];
        yield 'reserved core namespace type' => ['core.note'];
    }

    #[Test]
    #[DataProvider('malformedTargetMetadataProvider')]
    public function nonStringTargetMetadataFailsWithStableMissingTargetRefusal(mixed $target): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            EntityReferenceExistenceConstraintBuilder::existenceConstraints(
                'related_id',
                new FieldDefinition(
                    name: 'related_id',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => $target],
                ),
                $this->resolverForType('article'),
            );
            self::fail('Non-string target metadata was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame(
                sprintf(EntityReferenceExistenceConstraintBuilder::MISSING_TARGET_MESSAGE, 'related_id'),
                $exception->getMessage(),
            );
        } finally {
            restore_error_handler();
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedTargetMetadataProvider(): iterable
    {
        yield 'array' => [['article']];
        yield 'object' => [(object) ['id' => 'article']];
        yield 'integer' => [123];
        yield 'boolean' => [true];
    }

    #[Test]
    public function registryBoundHostIdentityCannotStandInForMissingReferenceTargetMetadata(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('author_id');

        EntityReferenceExistenceConstraintBuilder::existenceConstraints(
            'author_id',
            new FieldDefinition(
                name: 'author_id',
                type: 'entity_reference',
                settings: ['target_entity_type_id' => ''],
                targetEntityTypeId: 'article',
            ),
            $this->resolverForType('article'),
        );
    }

    #[Test]
    public function registryBoundHostTargetEntityTypeIdDoesNotOverrideSettingsReferenceDestination(): void
    {
        $findCalls = [];
        $hostRepository = $this->createMock(EntityRepositoryInterface::class);
        $hostRepository->expects(self::never())->method('find');

        $userRepository = $this->createStub(EntityRepositoryInterface::class);
        $userRepository->method('find')->willReturnCallback(
            static function (string $id) use (&$findCalls): ?EntityInterface {
                $findCalls[] = $id;

                return $id === '1' ? new TestEntity(['id' => '1']) : null;
            },
        );

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn(string $entityTypeId): EntityRepositoryInterface => $entityTypeId === 'user'
                ? $userRepository
                : $hostRepository,
        );
        $manager->method('getDefinition')->willReturnCallback(
            static fn(string $entityTypeId) => TestEntityType::stub($entityTypeId, keys: ['id' => 'id']),
        );

        $definition = new FieldDefinition(
            name: 'author_id',
            type: 'entity_reference',
            settings: ['target_entity_type_id' => 'user'],
            targetEntityTypeId: 'article',
        );

        $constraints = EntityReferenceExistenceConstraintBuilder::existenceConstraints(
            'author_id',
            $definition,
            new EntityIdentifierResolver($manager),
        );

        self::assertInstanceOf(EntityExists::class, $constraints[0]);
        self::assertSame('user', $constraints[0]->entityTypeId);

        $violations = new EntityValidator(Validation::createValidator())->validate(
            $this->stubEntity(['author_id' => 1]),
            ['author_id' => $constraints],
        );

        self::assertCount(0, $violations);
        self::assertSame(['1'], $findCalls);
    }

    #[Test]
    public function singleCardinalityAddsEntityExistsConstraint(): void
    {
        $constraints = EntityReferenceExistenceConstraintBuilder::existenceConstraints(
            'author_id',
            new FieldDefinition(
                name: 'author_id',
                type: 'entity_reference',
                settings: ['target_entity_type_id' => 'user'],
            ),
            $this->resolverForType('user'),
        );

        self::assertCount(1, $constraints);
        self::assertInstanceOf(EntityExists::class, $constraints[0]);
        self::assertSame('user', $constraints[0]->entityTypeId);
    }

    #[Test]
    public function multipleCardinalityWrapsEntityExistsInAll(): void
    {
        $constraints = EntityReferenceExistenceConstraintBuilder::existenceConstraints(
            'tag_ids',
            new FieldDefinition(
                name: 'tag_ids',
                type: 'entity_reference',
                cardinality: -1,
                settings: ['target_entity_type_id' => 'taxonomy_term'],
            ),
            $this->resolverForType('taxonomy_term'),
        );

        self::assertCount(1, $constraints);
        self::assertInstanceOf(All::class, $constraints[0]);
    }

    #[Test]
    public function entityTypeValidationConstraintsAppendsExistenceWhenResolverSupplied(): void
    {
        $type = new \Waaseyaa\Entity\EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: [
                'author_id' => new FieldDefinition(
                    name: 'author_id',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => 'user'],
                ),
            ],
        );

        $merged = EntityTypeValidationConstraints::forEntityType(
            $type,
            null,
            $this->resolverForType('user'),
        );

        self::assertCount(1, $merged['author_id']);
        self::assertInstanceOf(EntityExists::class, $merged['author_id'][0]);
    }

    #[Test]
    #[DataProvider('optionalSingleCardinalityValuesProvider')]
    public function optionalSingleCardinalityValuesPassWithoutCallingResolver(mixed $value): void
    {
        $repository = $this->repositoryExpectingNoFind();

        $violations = $this->validateSingle(
            $value,
            EntityReferenceExistenceConstraintBuilder::existenceConstraints(
                'author_id',
                new FieldDefinition(
                    name: 'author_id',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => 'user'],
                ),
                new EntityIdentifierResolver($this->entityTypeManager($repository, 'user')),
            ),
        );

        self::assertCount(0, $violations);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function optionalSingleCardinalityValuesProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'empty array' => [[]];
    }

    #[Test]
    #[DataProvider('malformedSingleCardinalityValuesProvider')]
    public function malformedSingleCardinalityValuesProduceConstraintViolationsWithoutTypeError(mixed $value): void
    {
        $repository = $this->repositoryExpectingNoFind();

        $violations = $this->validateSingle(
            $value,
            EntityReferenceExistenceConstraintBuilder::existenceConstraints(
                'author_id',
                new FieldDefinition(
                    name: 'author_id',
                    type: 'entity_reference',
                    settings: ['target_entity_type_id' => 'user'],
                ),
                new EntityIdentifierResolver($this->entityTypeManager($repository, 'user')),
            ),
        );

        self::assertGreaterThan(0, $violations->count());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedSingleCardinalityValuesProvider(): iterable
    {
        yield 'non-empty array' => [[1]];
        yield 'bool true' => [true];
        yield 'object' => [(object) ['id' => 1]];
        yield 'float' => [1.5];
    }

    #[Test]
    public function multipleCardinalityValidatesEveryScalarMemberWithoutTypeErrorOnMalformedMembers(): void
    {
        $findCalls = [];
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(
            static function (string $id) use (&$findCalls): ?EntityInterface {
                $findCalls[] = $id;

                return $id === '1' ? new TestEntity(['id' => '1']) : null;
            },
        );

        $constraints = EntityReferenceExistenceConstraintBuilder::existenceConstraints(
            'tag_ids',
            new FieldDefinition(
                name: 'tag_ids',
                type: 'entity_reference',
                cardinality: -1,
                settings: ['target_entity_type_id' => 'taxonomy_term'],
            ),
            new EntityIdentifierResolver($this->entityTypeManager($repository, 'taxonomy_term')),
        );

        $validator = new EntityValidator(Validation::createValidator());
        $violations = $validator->validate(
            $this->stubEntity(['tag_ids' => [1, true, 'missing']]),
            ['tag_ids' => $constraints],
        );

        self::assertSame(['1', 'missing'], $findCalls);
        self::assertGreaterThanOrEqual(2, $violations->count());
    }

    #[Test]
    public function multipleCardinalityOptionalEmptyArrayPassesWithoutCallingResolver(): void
    {
        $repository = $this->repositoryExpectingNoFind();

        $constraints = EntityReferenceExistenceConstraintBuilder::existenceConstraints(
            'tag_ids',
            new FieldDefinition(
                name: 'tag_ids',
                type: 'entity_reference',
                cardinality: -1,
                settings: ['target_entity_type_id' => 'taxonomy_term'],
            ),
            new EntityIdentifierResolver($this->entityTypeManager($repository, 'taxonomy_term')),
        );

        $violations = new EntityValidator(Validation::createValidator())->validate(
            $this->stubEntity(['tag_ids' => []]),
            ['tag_ids' => $constraints],
        );

        self::assertCount(0, $violations);
    }

    private function resolverForType(string $entityTypeId): EntityIdentifierResolver
    {
        return new EntityIdentifierResolver(
            $this->entityTypeManager($this->repository([]), $entityTypeId),
        );
    }

    /** @param array<string, EntityInterface> $entitiesById */
    private function repository(array $entitiesById): EntityRepositoryInterface
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(
            static fn(string $id): ?EntityInterface => $entitiesById[$id] ?? null,
        );

        return $repository;
    }

    private function repositoryExpectingNoFind(): EntityRepositoryInterface
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('find');

        return $repository;
    }

    /** @param array<string, string> $keys */
    private function entityTypeManager(
        EntityRepositoryInterface $repository,
        string $entityTypeId,
        array $keys = ['id' => 'id'],
    ): EntityTypeManagerInterface {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinition')->willReturn(TestEntityType::stub($entityTypeId, keys: $keys));
        $manager->method('getRepository')->willReturn($repository);

        return $manager;
    }

    /**
     * @param list<\Symfony\Component\Validator\Constraint> $constraints
     */
    private function validateSingle(mixed $value, array $constraints): \Symfony\Component\Validator\ConstraintViolationListInterface
    {
        return new EntityValidator(Validation::createValidator())->validate(
            $this->stubEntity(['author_id' => $value]),
            ['author_id' => $constraints],
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function stubEntity(array $values): FieldableEntityDouble
    {
        $entity = $this->createStub(FieldableEntityDouble::class);
        $entity->method('get')->willReturnCallback(
            static fn(string $name): mixed => $values[$name] ?? null,
        );

        return $entity;
    }
}
