<?php

declare(strict_types=1);

namespace Waaseyaa\Validation\Tests\Unit\Constraint;

use Waaseyaa\Validation\Constraint\NotEmpty;
use Waaseyaa\Validation\Constraint\NotEmptyValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

final class NotEmptyValidatorTest extends TestCase
{
    private NotEmptyValidator $validator;
    private ExecutionContextInterface $context;
    private ConstraintViolationBuilderInterface $violationBuilder;

    protected function setUp(): void
    {
        $this->validator = new NotEmptyValidator();

        $this->violationBuilder = $this->createStub(ConstraintViolationBuilderInterface::class);
        $this->violationBuilder->method('setParameter')->willReturnSelf();
        $this->violationBuilder->method('setCode')->willReturnSelf();
        // addViolation() returns void; no willReturn needed.

        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    public function testNullValueTriggersViolation(): void
    {
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($this->violationBuilder);

        $this->validator->validate(null, new NotEmpty());
    }

    public function testEmptyStringTriggersViolation(): void
    {
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($this->violationBuilder);

        $this->validator->validate('', new NotEmpty());
    }

    public function testWhitespaceOnlyStringTriggersViolation(): void
    {
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($this->violationBuilder);

        $this->validator->validate('   ', new NotEmpty());
    }

    public function testEmptyArrayTriggersViolation(): void
    {
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->willReturn($this->violationBuilder);

        $this->validator->validate([], new NotEmpty());
    }

    public function testNonEmptyStringPasses(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate('hello', new NotEmpty());
    }

    public function testNonEmptyArrayPasses(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate(['item'], new NotEmpty());
    }

    public function testIntegerZeroPasses(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate(0, new NotEmpty());
    }

    public function testBooleanFalsePasses(): void
    {
        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate(false, new NotEmpty());
    }

    public function testCustomMessage(): void
    {
        $customMessage = 'Field is required.';
        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($customMessage)
            ->willReturn($this->violationBuilder);

        $this->validator->validate(null, new NotEmpty(message: $customMessage));
    }
}
