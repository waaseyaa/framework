<?php

declare(strict_types=1);

namespace Waaseyaa\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates that a referenced entity exists using a callback checker.
 */
final class EntityExistsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EntityExists) {
            throw new UnexpectedTypeException($constraint, EntityExists::class);
        }

        // Allow null values to pass through; use NotEmpty to reject null.
        if ($value === null || $value === '') {
            return;
        }

        if ($constraint->existsChecker === null) {
            throw new \InvalidArgumentException('The existsChecker option must be a callable; null given.');
        }

        $checker = $constraint->existsChecker;

        if (!\is_callable($checker)) {
            throw new \InvalidArgumentException('The existsChecker option must be a callable.');
        }

        if (!$checker($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setParameter('{{ entity_type }}', $constraint->entityTypeId)
                ->addViolation();
        }
    }
}
