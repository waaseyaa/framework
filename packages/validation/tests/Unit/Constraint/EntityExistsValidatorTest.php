<?php

declare(strict_types=1);

namespace Waaseyaa\Validation\Tests\Unit\Constraint;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;
use Waaseyaa\Validation\Constraint\EntityExists;
use Waaseyaa\Validation\Constraint\EntityExistsValidator;

final class EntityExistsValidatorTest extends TestCase
{
    private EntityExistsValidator $validator;
    private ExecutionContextInterface $context;
    private ConstraintViolationBuilderInterface $violationBuilder;

    protected function setUp(): void
    {
        $this->validator = new EntityExistsValidator();

        $this->violationBuilder = $this->createStub(ConstraintViolationBuilderInterface::class);
        $this->violationBuilder->method('setParameter')->willReturnSelf();
        $this->violationBuilder->method('setCode')->willReturnSelf();
        // addViolation() returns void; no willReturn needed.

        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    public function testExistingEntityPasses(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new EntityExists(
            entityTypeId: 'node',
            existsChecker: fn(mixed $id): bool => true,
        );

        $this->validator->validate(42, $constraint);
    }

    public function testNonExistingEntityTriggersViolation(): void
    {
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($this->violationBuilder);

        $constraint = new EntityExists(
            entityTypeId: 'node',
            existsChecker: fn(mixed $id): bool => false,
        );

        $this->validator->validate(999, $constraint);
    }

    public function testNullValueIsSkipped(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new EntityExists(
            entityTypeId: 'node',
            existsChecker: fn(mixed $id): bool => false,
        );

        $this->validator->validate(null, $constraint);
    }

    public function testEmptyStringIsSkipped(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new EntityExists(
            entityTypeId: 'node',
            existsChecker: fn(mixed $id): bool => false,
        );

        $this->validator->validate('', $constraint);
    }

    public function testNullCheckerThrows(): void
    {
        $this->context->expects(self::never())->method('buildViolation');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The existsChecker option must be a callable; null given.');

        $constraint = new EntityExists(
            entityTypeId: 'node',
        );

        $this->validator->validate(42, $constraint);
    }

    public function testStringEntityIdWorks(): void
    {
        $this->context->expects(self::never())->method('buildViolation');

        $receivedId = null;

        $constraint = new EntityExists(
            entityTypeId: 'taxonomy_term',
            existsChecker: function (mixed $id) use (&$receivedId): bool {
                $receivedId = $id;
                return true;
            },
        );

        $this->validator->validate('abc-123', $constraint);

        $this->assertSame('abc-123', $receivedId);
    }

    public function testCustomMessage(): void
    {
        $customMessage = 'Referenced entity not found.';
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($customMessage)
            ->willReturn($this->violationBuilder);

        $constraint = new EntityExists(
            entityTypeId: 'node',
            existsChecker: fn(mixed $id): bool => false,
            message: $customMessage,
        );

        $this->validator->validate(999, $constraint);
    }
}
