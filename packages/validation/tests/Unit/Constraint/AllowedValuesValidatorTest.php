<?php

declare(strict_types=1);

namespace Waaseyaa\Validation\Tests\Unit\Constraint;

use Waaseyaa\Validation\Constraint\AllowedValues;
use Waaseyaa\Validation\Constraint\AllowedValuesValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

final class AllowedValuesValidatorTest extends TestCase
{
    private AllowedValuesValidator $validator;
    private ExecutionContextInterface $context;
    private ConstraintViolationBuilderInterface $violationBuilder;

    protected function setUp(): void
    {
        $this->validator = new AllowedValuesValidator();

        $this->violationBuilder = $this->createStub(ConstraintViolationBuilderInterface::class);
        $this->violationBuilder->method('setParameter')->willReturnSelf();
        $this->violationBuilder->method('setCode')->willReturnSelf();
        // addViolation() returns void; no willReturn needed.

        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    public function testValueInListPasses(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new AllowedValues(values: ['draft', 'published', 'archived']);
        $this->validator->validate('published', $constraint);
    }

    public function testValueNotInListTriggersViolation(): void
    {
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($this->violationBuilder);

        $constraint = new AllowedValues(values: ['draft', 'published', 'archived']);
        $this->validator->validate('deleted', $constraint);
    }

    public function testStrictModeRejectsLooseMatch(): void
    {
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($this->violationBuilder);

        $constraint = new AllowedValues(values: [1, 2, 3], strict: true);
        $this->validator->validate('1', $constraint);
    }

    public function testNonStrictModeAllowsLooseMatch(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new AllowedValues(values: [1, 2, 3], strict: false);
        $this->validator->validate('1', $constraint);
    }

    public function testNullValueIsSkipped(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new AllowedValues(values: ['a', 'b']);
        $this->validator->validate(null, $constraint);
    }

    public function testIntegerValueInList(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $constraint = new AllowedValues(values: [10, 20, 30]);
        $this->validator->validate(20, $constraint);
    }

    public function testCustomMessage(): void
    {
        $customMessage = 'Invalid status.';
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($customMessage)
            ->willReturn($this->violationBuilder);

        $constraint = new AllowedValues(values: ['a', 'b'], message: $customMessage);
        $this->validator->validate('c', $constraint);
    }
}
