<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Validation;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Validation\Constraint\AllowedValues;
use Waaseyaa\Validation\Constraint\NotEmpty;
use Waaseyaa\Entity\Validation\EntityValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class EntityValidatorTest extends TestCase
{
    public function testValidateEntityWithNoViolations(): void
    {
        $entity = $this->createFieldableEntity([
            'title' => 'My Article',
            'status' => 'published',
        ]);

        $symfonyValidator = $this->createMock(ValidatorInterface::class);
        $symfonyValidator->method('validate')
            ->willReturn(new ConstraintViolationList());

        $validator = new EntityValidator($symfonyValidator);
        $violations = $validator->validate($entity, [
            'title' => [new NotEmpty()],
            'status' => [new AllowedValues(values: ['draft', 'published'])],
        ]);

        $this->assertCount(0, $violations);
    }

    public function testValidateEntityWithViolations(): void
    {
        $entity = $this->createFieldableEntity([
            'title' => '',
            'status' => 'invalid',
        ]);

        $titleViolation = new ConstraintViolation(
            'This value must not be empty.',
            'This value must not be empty.',
            [],
            $entity,
            '',
            '',
        );

        $statusViolation = new ConstraintViolation(
            'The value "invalid" is not a valid choice.',
            'The value "{{ value }}" is not a valid choice.',
            ['{{ value }}' => 'invalid'],
            $entity,
            '',
            'invalid',
        );

        $symfonyValidator = $this->createMock(ValidatorInterface::class);
        $symfonyValidator->method('validate')
            ->willReturnOnConsecutiveCalls(
                new ConstraintViolationList([$titleViolation]),
                new ConstraintViolationList([$statusViolation]),
            );

        $validator = new EntityValidator($symfonyValidator);
        $violations = $validator->validate($entity, [
            'title' => [new NotEmpty()],
            'status' => [new AllowedValues(values: ['draft', 'published'])],
        ]);

        $this->assertCount(2, $violations);
        $this->assertSame('title', $violations->get(0)->getPropertyPath());
        $this->assertSame('status', $violations->get(1)->getPropertyPath());
    }

    public function testValidateEntityWithMultipleConstraintsPerField(): void
    {
        $entity = $this->createFieldableEntity([
            'title' => '',
        ]);

        $violation = new ConstraintViolation(
            'This value must not be empty.',
            'This value must not be empty.',
            [],
            $entity,
            '',
            '',
        );

        $symfonyValidator = $this->createMock(ValidatorInterface::class);
        $symfonyValidator->expects($this->once())
            ->method('validate')
            ->with('', $this->isType('array'))
            ->willReturn(new ConstraintViolationList([$violation]));

        $validator = new EntityValidator($symfonyValidator);
        $violations = $validator->validate($entity, [
            'title' => [new NotEmpty(), new AllowedValues(values: ['a', 'b'])],
        ]);

        $this->assertCount(1, $violations);
    }

    public function testValidateNonFieldableEntityUsesToArray(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('toArray')->willReturn([
            'title' => 'Hello',
        ]);

        $symfonyValidator = $this->createMock(ValidatorInterface::class);
        $symfonyValidator->expects($this->once())
            ->method('validate')
            ->with('Hello', $this->anything())
            ->willReturn(new ConstraintViolationList());

        $validator = new EntityValidator($symfonyValidator);
        $violations = $validator->validate($entity, [
            'title' => new NotEmpty(),
        ]);

        $this->assertCount(0, $violations);
    }

    public function testValidateWithEmptyConstraintsReturnsNoViolations(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('toArray')->willReturn(['title' => '']);

        $symfonyValidator = $this->createMock(ValidatorInterface::class);
        $symfonyValidator->expects($this->never())->method('validate');

        $validator = new EntityValidator($symfonyValidator);
        $violations = $validator->validate($entity, []);

        $this->assertCount(0, $violations);
    }

    public function testViolationRootIsEntity(): void
    {
        $entity = $this->createFieldableEntity([
            'title' => '',
        ]);

        $violation = new ConstraintViolation(
            'This value must not be empty.',
            'This value must not be empty.',
            [],
            'root',
            '',
            '',
        );

        $symfonyValidator = $this->createMock(ValidatorInterface::class);
        $symfonyValidator->method('validate')
            ->willReturn(new ConstraintViolationList([$violation]));

        $validator = new EntityValidator($symfonyValidator);
        $violations = $validator->validate($entity, [
            'title' => [new NotEmpty()],
        ]);

        // Root should be remapped to the entity.
        $this->assertSame($entity, $violations->get(0)->getRoot());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function createFieldableEntity(array $values): EntityInterface&FieldableInterface
    {
        $entity = $this->createMock(FieldableEntityStub::class);
        $entity->method('toArray')->willReturn($values);
        $entity->method('get')->willReturnCallback(
            fn (string $name): mixed => $values[$name] ?? null,
        );

        return $entity;
    }
}

/**
 * @internal Stub interface for mocking both EntityInterface and FieldableInterface.
 */
interface FieldableEntityStub extends EntityInterface, FieldableInterface
{
}
