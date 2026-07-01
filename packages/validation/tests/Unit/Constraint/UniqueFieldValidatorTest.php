<?php

declare(strict_types=1);

namespace Waaseyaa\Validation\Tests\Unit\Constraint;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;
use Waaseyaa\Validation\Constraint\UniqueField;
use Waaseyaa\Validation\Constraint\UniqueFieldValidator;

final class UniqueFieldValidatorTest extends TestCase
{
    private UniqueFieldValidator $validator;
    private ExecutionContextInterface $context;
    private ConstraintViolationBuilderInterface $violationBuilder;

    protected function setUp(): void
    {
        $this->validator = new UniqueFieldValidator();

        $this->violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $this->violationBuilder->method('setParameter')->willReturnSelf();
        $this->violationBuilder->method('setCode')->willReturnSelf();
        // addViolation() returns void; no willReturn needed.

        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    public function testUniqueValuePasses(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new UniqueField(
            entityTypeId: 'node',
            fieldName: 'title',
            existsChecker: fn(mixed $value): bool => false,
        );

        $this->validator->validate('Unique Title', $constraint);
    }

    public function testDuplicateValueTriggersViolation(): void
    {
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($this->violationBuilder);

        $constraint = new UniqueField(
            entityTypeId: 'node',
            fieldName: 'title',
            existsChecker: fn(mixed $value): bool => true,
        );

        $this->validator->validate('Duplicate Title', $constraint);
    }

    public function testNullValueIsSkipped(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new UniqueField(
            entityTypeId: 'node',
            fieldName: 'title',
            existsChecker: fn(mixed $value): bool => true,
        );

        $this->validator->validate(null, $constraint);
    }

    public function testEmptyStringIsSkipped(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new UniqueField(
            entityTypeId: 'node',
            fieldName: 'title',
            existsChecker: fn(mixed $value): bool => true,
        );

        $this->validator->validate('', $constraint);
    }

    public function testNullCheckerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The existsChecker option must be a callable; null given.');

        $constraint = new UniqueField(
            entityTypeId: 'node',
            fieldName: 'title',
        );

        $this->validator->validate('Some Title', $constraint);
    }

    public function testCheckerReceivesCorrectValue(): void
    {
        $receivedValue = null;

        $constraint = new UniqueField(
            entityTypeId: 'user',
            fieldName: 'email',
            existsChecker: function (mixed $value) use (&$receivedValue): bool {
                $receivedValue = $value;
                return false;
            },
        );

        $this->validator->validate('test@example.com', $constraint);

        $this->assertSame('test@example.com', $receivedValue);
    }
}
