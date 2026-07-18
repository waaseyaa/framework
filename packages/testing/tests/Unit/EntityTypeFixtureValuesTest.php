<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Entity\Validation\EntityTypeValidationConstraints;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Entity\Validation\ValidationFieldReader;
use Waaseyaa\Entity\Validation\ValidationReadLedgerInterface;
use Waaseyaa\Entity\Validation\ValidationReadReservationInterface;
use Waaseyaa\Testing\Factory\EntityTypeFixtureValues;
use Waaseyaa\Testing\Tests\Fixture\StubFieldableEntity;

#[CoversClass(EntityTypeFixtureValues::class)]
final class EntityTypeFixtureValuesTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = Validation::createValidator();
    }

    #[Test]
    public function generatedValuesPassEntityValidator(): void
    {
        $type = $this->makeArticleType();
        $values = (new EntityTypeFixtureValues(sequence: 1))->values($type);

        $entity = new StubFieldableEntity($values);
        $constraints = EntityTypeValidationConstraints::forEntityType($type);
        $violations = $this->closedEntityValidator()->validate($entity, $constraints);

        self::assertCount(0, $violations, (string) $violations);
    }

    #[Test]
    public function overridesWin(): void
    {
        $type = $this->makeArticleType();
        $values = (new EntityTypeFixtureValues(sequence: 1))->values($type, [
            'title' => 'Fixed title',
            'status' => 'draft',
        ]);

        self::assertSame('Fixed title', $values['title']);
        self::assertSame('draft', $values['status']);
    }

    #[Test]
    public function respectsLengthMax(): void
    {
        $type = TestEntityType::stub(
            id: 'code_item',
            class: StubFieldableEntity::class,
            label: 'Code',
            fieldDefinitions: [
                'code' => ['type' => 'string', 'maxLength' => 4],
            ],
        );
        $values = (new EntityTypeFixtureValues(sequence: 1))->values($type);

        self::assertArrayHasKey('code', $values);
        self::assertLessThanOrEqual(4, strlen((string) $values['code']));

        $entity = new StubFieldableEntity($values);
        $constraints = EntityTypeValidationConstraints::forEntityType($type);
        $violations = $this->closedEntityValidator()->validate($entity, $constraints);
        self::assertCount(0, $violations, (string) $violations);
    }

    #[Test]
    public function choiceCyclesWithSequence(): void
    {
        $type = TestEntityType::stub(
            id: 'status_item',
            class: StubFieldableEntity::class,
            label: 'Status',
            fieldDefinitions: [
                'status' => [
                    'type' => 'string',
                    'allowed_values' => ['draft', 'published'],
                ],
            ],
        );
        $first = (new EntityTypeFixtureValues(sequence: 1))->values($type);
        $second = (new EntityTypeFixtureValues(sequence: 2))->values($type);

        self::assertSame('draft', $first['status']);
        self::assertSame('published', $second['status']);
    }

    #[Test]
    public function emailFieldUsesValidAddress(): void
    {
        $type = TestEntityType::stub(
            id: 'user_stub',
            class: StubFieldableEntity::class,
            label: 'User',
            fieldDefinitions: [
                'mail' => ['type' => 'email'],
            ],
        );
        $values = (new EntityTypeFixtureValues(sequence: 3))->values($type);

        self::assertStringContainsString('@', (string) $values['mail']);

        $entity = new StubFieldableEntity($values);
        $constraints = EntityTypeValidationConstraints::forEntityType($type);
        $violations = $this->closedEntityValidator()->validate($entity, $constraints);
        self::assertCount(0, $violations, (string) $violations);
    }

    #[Test]
    public function manualConstraintsWithoutFieldDefinition(): void
    {
        $type = new EntityType(
            id: 'legacy',
            label: 'Legacy',
            class: StubFieldableEntity::class,
            constraints: [
                'note' => new NotBlank(allowNull: false),
            ],
        );
        $values = (new EntityTypeFixtureValues(sequence: 1))->values($type);

        self::assertArrayHasKey('note', $values);
        self::assertNotSame('', trim((string) $values['note']));

        $entity = new StubFieldableEntity($values);
        $constraints = EntityTypeValidationConstraints::forEntityType($type);
        $violations = $this->closedEntityValidator()->validate($entity, $constraints);
        self::assertCount(0, $violations, (string) $violations);
    }

    #[Test]
    public function customResolverOverridesBuiltIn(): void
    {
        $type = $this->makeArticleType();
        $values = (new EntityTypeFixtureValues(
            sequence: 1,
            customResolver: static fn (string $field, array $constraints): ?string => $field === 'title' ? 'From resolver' : null,
        ))->values($type);

        self::assertSame('From resolver', $values['title']);
    }

    #[Test]
    public function entityKeyDefaultsPopulateUuidAndTitle(): void
    {
        $type = TestEntityType::stub(
            id: 'node_like',
            class: StubFieldableEntity::class,
            keys: [
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
                'langcode' => 'langcode',
            ],
            label: 'Node',
            fieldDefinitions: [
                'body' => ['type' => 'text', 'required' => true],
            ],
        );
        $values = (new EntityTypeFixtureValues(sequence: 1))->values($type);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $values['uuid'],
        );
        self::assertNotSame('', (string) $values['title']);
        self::assertSame('node_like', $values['type']);
        self::assertSame('en', $values['langcode']);
    }

    private function makeArticleType(): EntityTypeInterface
    {
        return TestEntityType::stub(
            id: 'article',
            class: StubFieldableEntity::class,
            label: 'Article',
            fieldDefinitions: [
                'title' => ['type' => 'string', 'required' => true],
                'status' => [
                    'type' => 'string',
                    'required' => true,
                    'allowed_values' => ['draft', 'published'],
                ],
                'body' => ['type' => 'text', 'required' => false],
            ],
        );
    }

    private function closedEntityValidator(): EntityValidator
    {
        $ledger = new class implements ValidationReadLedgerInterface {
            public function reserve(EntityStructure $subject, string $field): ValidationReadReservationInterface
            {
                return new class implements ValidationReadReservationInterface {
                    public function finalize(bool $success): void {}
                };
            }
        };

        return new EntityValidator($this->validator, new ValidationFieldReader($ledger));
    }
}
