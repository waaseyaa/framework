<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Validation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleIntEnum;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleStringEnum;
use Waaseyaa\Entity\Tests\Unit\Validation\Fixture\FieldableEntityDouble;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Entity\Validation\FieldDefinitionConstraintBuilder;
use Waaseyaa\Field\Item\EnumFieldTypeException;

#[CoversClass(FieldDefinitionConstraintBuilder::class)]
final class FieldDefinitionConstraintBuilderTest extends TestCase
{
    #[Test]
    public function integerTimestampAcceptsCastAwareDateTimeAndRejectsUnrelatedTypes(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'created' => ['type' => 'integer', 'subtype' => 'timestamp'],
        ]);

        foreach ([0, new DateTimeImmutable('@0')] as $validValue) {
            $valid = $this->stubEntity(['created' => $validValue]);
            self::assertCount(
                0,
                (new EntityValidator(Validation::createValidator()))->validate($valid, $constraints),
                'A timestamp must accept both its unix storage value and its cast-aware domain DateTime.',
            );
        }

        foreach (['not-a-timestamp', 1.5, []] as $invalid) {
            $entity = $this->stubEntity(['created' => $invalid]);
            self::assertGreaterThan(
                0,
                (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints)->count(),
                sprintf('Timestamp subtype must reject %s.', get_debug_type($invalid)),
            );
        }
    }

    #[Test]
    public function requiredStringRejectsBlank(): void
    {
        $entity = $this->stubEntity(['title' => '']);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'title' => ['type' => 'string', 'required' => true],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('title', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function optionalFieldAllowsNull(): void
    {
        $entity = $this->stubEntity(['subtitle' => null]);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'subtitle' => ['type' => 'string', 'required' => false],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function allowedValuesRejectsInvalidChoice(): void
    {
        $entity = $this->stubEntity(['status' => 'archived']);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'status' => [
                'type' => 'string',
                'allowed_values' => ['draft', 'published'],
            ],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('status', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function camelCaseAllowedValuesAliasWorks(): void
    {
        $entity = $this->stubEntity(['status' => 'ok']);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'status' => [
                'type' => 'string',
                'allowedValues' => ['ok'],
            ],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function maxLengthRejectsLongString(): void
    {
        $entity = $this->stubEntity(['code' => 'abcdef']);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'code' => ['type' => 'string', 'maxLength' => 3],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('code', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function emailTypeRejectsInvalidEmail(): void
    {
        $entity = $this->stubEntity(['mail' => 'not-an-email']);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'mail' => ['type' => 'email'],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('mail', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function emailTypeAcceptsValidEmail(): void
    {
        $entity = $this->stubEntity(['mail' => 'user@example.com']);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'mail' => ['type' => 'email'],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function enumTypeRejectsValueOutsideStringBackedCases(): void
    {
        $entity = $this->stubEntity(['letter' => 'z']);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'letter' => ['type' => 'enum', 'enum_class' => SampleStringEnum::class],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('letter', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function enumTypeAcceptsValidStringBackedCase(): void
    {
        $entity = $this->stubEntity(['letter' => 'a']);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'letter' => ['type' => 'enum', 'enum_class' => SampleStringEnum::class],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function enumTypeRejectsValueOutsideIntBackedCases(): void
    {
        $entity = $this->stubEntity(['count' => 99]);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => ['type' => 'enum', 'enum_class' => SampleIntEnum::class],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('count', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function stringTypeWithEnumClassDoesNotAddChoiceConstraint(): void
    {
        // Legacy 'string + enum_class' bridge is gone (C-004): a value outside
        // the enum cases must NOT produce a violation when type is 'string'.
        $entity = $this->stubEntity(['letter' => 'z']);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'letter' => ['type' => 'string', 'enum_class' => SampleStringEnum::class],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function enumTypeWithoutEnumClassPropagatesMissingEnumClass(): void
    {
        try {
            FieldDefinitionConstraintBuilder::build([
                'letter' => ['type' => 'enum'],
            ]);
            self::fail('Expected EnumFieldTypeException::MISSING_ENUM_CLASS to propagate.');
        } catch (EnumFieldTypeException $e) {
            self::assertSame(EnumFieldTypeException::MISSING_ENUM_CLASS, $e->variant);
        }
    }

    #[Test]
    public function requiredBooleanAllowsFalse(): void
    {
        $entity = $this->stubEntity(['active' => false]);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'active' => ['type' => 'boolean', 'required' => true],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function requiredBooleanRejectsNull(): void
    {
        $entity = $this->stubEntity(['active' => null]);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'active' => ['type' => 'boolean', 'required' => true],
        ]);

        $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('active', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function booleanFieldAcceptsBoolAndIntZeroOne(): void
    {
        // #1655: the framework's boolean convention keeps 0/1 through
        // get()/validate() (User.php "status stays 0/1" comment), so the
        // derived constraint must accept the convention, not just bool.
        $constraints = FieldDefinitionConstraintBuilder::build([
            'active' => ['type' => 'boolean'],
        ]);

        foreach ([true, false, 0, 1] as $value) {
            $entity = $this->stubEntity(['active' => $value]);
            $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

            self::assertCount(
                0,
                $violations,
                sprintf('Boolean field must accept %s (#1655 framework 0/1 convention).', var_export($value, true)),
            );
        }
    }

    #[Test]
    public function booleanFieldRejectsValuesOutsideConvention(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'active' => ['type' => 'boolean'],
        ]);

        // Choice compares strictly: '1'/'0' never match bool/int choices,
        // 2 matches nothing, and 1.0 !== 1 (a float is never identical to an
        // int) — pinned against observed Choice behaviour, not assumed.
        foreach (['1', '0', 2, 1.0] as $value) {
            $entity = $this->stubEntity(['active' => $value]);
            $violations = (new EntityValidator(Validation::createValidator()))->validate($entity, $constraints);

            self::assertGreaterThan(
                0,
                $violations->count(),
                sprintf('Boolean field must reject %s.', var_export($value, true)),
            );
            self::assertSame('active', $violations->get(0)->getPropertyPath());
        }
    }

    #[Test]
    public function userShapedBooleanStatusWithoutCastAcceptsIntOneRejectsString(): void
    {
        // Pin for the original #1655 break: User::$status is declared
        // #[Field(type: 'boolean')] with deliberately NO bool cast, and
        // User::setActive() writes `$active ? 1 : 0`. alpha.204's Type('bool')
        // rejected the framework's own accessor output.
        $constraints = FieldDefinitionConstraintBuilder::build([
            'status' => ['type' => 'boolean', 'required' => true],
        ]);
        $validator = new EntityValidator(Validation::createValidator());

        self::assertCount(0, $validator->validate($this->stubEntity(['status' => 1]), $constraints));

        $violations = $validator->validate($this->stubEntity(['status' => 'yes']), $constraints);
        self::assertGreaterThan(0, $violations->count());
        self::assertSame('status', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function integerWithMinAndMaxDerivesRange(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => ['type' => 'integer', 'settings' => ['min' => 0, 'max' => 100]],
        ]);

        $range = $this->soleRange($constraints['count']);
        self::assertSame(0, $range->min);
        self::assertSame(100, $range->max);
    }

    #[Test]
    public function integerWithMinOnlyDerivesMinOnlyRange(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => ['type' => 'integer', 'settings' => ['min' => 5]],
        ]);

        $range = $this->soleRange($constraints['count']);
        self::assertSame(5, $range->min);
        self::assertNull($range->max);
    }

    #[Test]
    public function integerWithMaxOnlyDerivesMaxOnlyRange(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => ['type' => 'integer', 'settings' => ['max' => 10]],
        ]);

        $range = $this->soleRange($constraints['count']);
        self::assertNull($range->min);
        self::assertSame(10, $range->max);
    }

    #[Test]
    public function integerWithoutMinMaxSettingsDerivesNoRange(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => ['type' => 'integer'],
        ]);

        self::assertSame([], $this->rangesIn($constraints['count']));
    }

    #[Test]
    public function nonNumericMinMaxSettingsAreIgnored(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => ['type' => 'integer', 'settings' => ['min' => 'abc', 'max' => []]],
        ]);

        self::assertSame([], $this->rangesIn($constraints['count']));
    }

    #[Test]
    public function nonNumericTypeWithMinMaxSettingsDerivesNoRange(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'title' => ['type' => 'string', 'settings' => ['min' => 1, 'max' => 10]],
        ]);

        self::assertSame([], $this->rangesIn($constraints['title']));
    }

    #[Test]
    public function floatTypeDerivesFloatCastBounds(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'price' => ['type' => 'float', 'settings' => ['min' => '0.5', 'max' => 99]],
        ]);

        $range = $this->soleRange($constraints['price']);
        self::assertSame(0.5, $range->min);
        self::assertSame(99.0, $range->max);
    }

    #[Test]
    public function requiredIntegerWithRangeComposesNotNullAndRange(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => ['type' => 'integer', 'required' => true, 'settings' => ['min' => 0, 'max' => 100]],
        ]);

        self::assertCount(1, array_filter($constraints['count'], static fn ($c): bool => $c instanceof NotNull));
        self::assertCount(1, $this->rangesIn($constraints['count']));
    }

    #[Test]
    public function rangeDerivesFromFieldDefinitionInstance(): void
    {
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => new FieldDefinition(
                name: 'count',
                type: 'integer',
                settings: ['min' => 1, 'max' => 9],
            ),
        ]);

        $range = $this->soleRange($constraints['count']);
        self::assertSame(1, $range->min);
        self::assertSame(9, $range->max);
    }

    #[Test]
    public function declaredConstraintsAppendAfterDerived(): void
    {
        $declared = new GreaterThan(0);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => ['type' => 'integer', 'required' => true, 'constraints' => [$declared]],
        ]);

        $list = $constraints['count'];
        self::assertInstanceOf(NotNull::class, $list[0]);
        self::assertInstanceOf(Type::class, $list[1]);
        self::assertSame($declared, $list[array_key_last($list)]);
    }

    #[Test]
    public function declaredConstraintsAloneSurfaceFieldInOutput(): void
    {
        // 'entity_reference', not required: no derivable constraints at all.
        $declared = new GreaterThan(0);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'ref' => ['type' => 'entity_reference', 'constraints' => [$declared]],
        ]);

        self::assertSame([$declared], $constraints['ref']);
    }

    #[Test]
    public function nonConstraintDeclaredEntryThrowsNamingTheField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"amount"');

        FieldDefinitionConstraintBuilder::build([
            'amount' => ['type' => 'integer', 'constraints' => ['not-a-constraint']],
        ]);
    }

    #[Test]
    public function declaredConstraintsMergeFromFieldDefinitionInstance(): void
    {
        $declared = new GreaterThan(0);
        $constraints = FieldDefinitionConstraintBuilder::build([
            'count' => new FieldDefinition(
                name: 'count',
                type: 'integer',
                constraints: [$declared],
            ),
        ]);

        self::assertSame($declared, $constraints['count'][array_key_last($constraints['count'])]);
    }

    /**
     * @param list<\Symfony\Component\Validator\Constraint> $constraints
     */
    private function soleRange(array $constraints): Range
    {
        $ranges = $this->rangesIn($constraints);
        self::assertCount(1, $ranges);

        return $ranges[0];
    }

    /**
     * @param list<\Symfony\Component\Validator\Constraint> $constraints
     *
     * @return list<Range>
     */
    private function rangesIn(array $constraints): array
    {
        return array_values(array_filter($constraints, static fn ($c): bool => $c instanceof Range));
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
