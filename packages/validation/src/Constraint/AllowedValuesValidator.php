<?php

declare(strict_types=1);

namespace Waaseyaa\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates that a value is in the list of allowed values.
 */
final class AllowedValuesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AllowedValues) {
            throw new UnexpectedTypeException($constraint, AllowedValues::class);
        }

        // Allow null values to pass through; use NotEmpty to reject null.
        if ($value === null) {
            return;
        }

        if (!\in_array($value, $constraint->values, $constraint->strict)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setParameter('{{ choices }}', implode(', ', array_map(
                    fn(mixed $v): string => $this->formatValue($v),
                    $constraint->values,
                )))
                ->addViolation();
        }
    }
}
