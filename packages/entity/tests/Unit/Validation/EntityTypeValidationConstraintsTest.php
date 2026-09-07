<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityIdentifierResolver;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\ConstraintsRequiredTitleFixture;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Entity\Tests\Unit\Validation\Fixture\FieldableEntityDouble;
use Waaseyaa\Entity\Validation\EntityTypeValidationConstraints;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Validation\Constraint\EntityExists;

require_once __DIR__ . '/../../Fixtures/AttributeFirstEntities/ValidationConstraintsFixtures.php';

#[CoversClass(EntityTypeValidationConstraints::class)]
final class EntityTypeValidationConstraintsTest extends TestCase
{
    protected function setUp(): void
    {
        EntityType::clearFromClassCache();
    }

    #[Test]
    public function manualConstraintsComposeWithMandatoryEntityReferenceExistence(): void
    {
        $manual = new Length(max: 3);
        $type = EntityType::fromClass(
            class: ConstraintsRequiredTitleFixture::class,
            constraints: ['author_id' => [$manual]],
        );
        $fieldDefinitions = [
            'author_id' => new FieldDefinition(
                name: 'author_id',
                type: 'entity_reference',
                settings: ['target_entity_type_id' => 'user'],
            ),
        ];

        $merged = EntityTypeValidationConstraints::forEntityType(
            $type,
            $fieldDefinitions,
            $this->resolverForType('user'),
        );

        self::assertCount(2, $merged['author_id']);
        self::assertSame($manual, $merged['author_id'][0]);
        self::assertInstanceOf(EntityExists::class, $merged['author_id'][1]);
    }

    #[Test]
    public function manualConstraintsReplaceDerivedScalarConstraintsButNotExistence(): void
    {
        $manual = new Length(max: 3);
        $type = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            constraints: ['author_id' => [$manual]],
            _fieldDefinitions: [
                'author_id' => new FieldDefinition(
                    name: 'author_id',
                    type: 'entity_reference',
                    required: true,
                    settings: ['target_entity_type_id' => 'user'],
                ),
            ],
        );

        $merged = EntityTypeValidationConstraints::forEntityType(
            $type,
            null,
            $this->resolverForType('user'),
        );

        self::assertCount(2, $merged['author_id']);
        self::assertSame($manual, $merged['author_id'][0], 'Manual constraints must still replace derived scalar constraints.');
        self::assertInstanceOf(EntityExists::class, $merged['author_id'][1], 'Existence must compose after manual precedence.');
    }

    #[Test]
    #[DataProvider('referenceTargetAliasProvider')]
    public function arrayFieldDefinitionsPreserveEverySupportedReferenceTargetAlias(string $alias): void
    {
        $type = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );

        $merged = EntityTypeValidationConstraints::forEntityType(
            $type,
            ['author_id' => ['type' => 'entity_reference', $alias => 'user']],
            $this->resolverForType('user'),
        );

        self::assertInstanceOf(EntityExists::class, $merged['author_id'][0]);
        self::assertSame('user', $merged['author_id'][0]->entityTypeId);
    }

    /** @return iterable<string, array{string}> */
    public static function referenceTargetAliasProvider(): iterable
    {
        yield 'canonical snake case' => ['target_entity_type_id'];
        yield 'camel case' => ['targetEntityTypeId'];
        yield 'legacy target type' => ['target_type'];
    }

    #[Test]
    public function manualConstraintsReplaceDerivedForSameField(): void
    {
        // Pattern 1: ConstraintsRequiredTitleFixture has `#[Field(required: true)]`
        // on `title`; manual constraints come through fromClass() overrides.
        $type = EntityType::fromClass(
            class: ConstraintsRequiredTitleFixture::class,
            constraints: ['title' => [new Length(max: 3)]],
        );

        $merged = EntityTypeValidationConstraints::forEntityType($type);

        $entity = $this->stubEntity(['title' => '']);
        $violations = new EntityValidator(Validation::createValidator())->validate($entity, $merged);

        self::assertCount(0, $violations, 'Manual constraints replaced derived NotBlank; empty title is allowed by Length(max:3).');
    }

    #[Test]
    public function manualConstraintsAugmentFieldsNotInManual(): void
    {
        $type = EntityType::fromClass(
            class: ConstraintsRequiredTitleFixture::class,
            constraints: ['slug' => [new NotBlank()]],
        );

        $merged = EntityTypeValidationConstraints::forEntityType($type);
        $entity = $this->stubEntity(['title' => 'ok', 'slug' => '']);

        $violations = new EntityValidator(Validation::createValidator())->validate($entity, $merged);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('slug', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function typeLevelManualConstraintsReplacePerFieldDeclaredConstraints(): void
    {
        // Precedence pin: when an entity type carries manual constraints for a
        // field AND the field definition declares its own constraints, the
        // type-level manual set wins entirely (replace, not merge) — the
        // per-field declared-constraint merge happens only inside the builder.
        $manual = new Length(max: 3);
        $type = EntityType::fromClass(
            class: ConstraintsRequiredTitleFixture::class,
            constraints: ['title' => [$manual]],
        );

        $fieldDefinitions = [
            'title' => new FieldDefinition(
                name: 'title',
                type: 'string',
                required: true,
                constraints: [new GreaterThan(0)],
            ),
        ];

        $merged = EntityTypeValidationConstraints::forEntityType($type, $fieldDefinitions);

        self::assertSame([$manual], $merged['title'], 'Type-level manual constraints must fully replace derived + per-field declared constraints.');
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

    private function resolverForType(string $entityTypeId): EntityIdentifierResolver
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('find');

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinition')->willReturn(TestEntityType::stub($entityTypeId, keys: ['id' => 'id']));
        $manager->method('getRepository')->willReturn($repository);

        return new EntityIdentifierResolver($manager);
    }
}
