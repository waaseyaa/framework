<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\ConstraintsRequiredTitleFixture;
use Waaseyaa\Entity\Tests\Unit\Validation\Fixture\FieldableEntityDouble;
use Waaseyaa\Entity\Validation\EntityTypeValidationConstraints;
use Waaseyaa\Entity\Validation\EntityValidator;

require_once __DIR__ . '/../../Fixtures/AttributeFirstEntities/ValidationConstraintsFixtures.php';

#[CoversClass(EntityTypeValidationConstraints::class)]
final class EntityTypeValidationConstraintsTest extends TestCase
{
    protected function setUp(): void
    {
        EntityType::clearFromClassCache();
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
        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $merged);

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

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $merged);

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
        $entity = $this->createMock(FieldableEntityDouble::class);
        $entity->method('get')->willReturnCallback(
            static fn (string $name): mixed => $values[$name] ?? null,
        );

        return $entity;
    }
}
