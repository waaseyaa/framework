<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\FieldableInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates entity field values against provided constraints.
 *
 * The EntityValidator takes a Symfony ValidatorInterface and applies
 * per-field constraints to entity values, collecting all violations
 * across all fields into a single violation list.
 */
final class EntityValidator
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * Validate an entity's values against the provided constraints.
     *
     * @param EntityInterface $entity The entity to validate.
     * @param array<string, \Symfony\Component\Validator\Constraint[]|\Symfony\Component\Validator\Constraint> $constraints
     *   An associative array keyed by field name, where each value is a
     *   Constraint or array of Constraints to apply to that field's value.
     *
     * @return ConstraintViolationListInterface All violations found across all fields.
     */
    public function validate(EntityInterface $entity, array $constraints = []): ConstraintViolationListInterface
    {
        $violations = new ConstraintViolationList();

        $values = $entity->toArray();

        foreach ($constraints as $field => $fieldConstraints) {
            // Normalize single constraint to array.
            if (!is_array($fieldConstraints)) {
                $fieldConstraints = [$fieldConstraints];
            }

            // Get the field value: prefer FieldableInterface::get() for proper resolution,
            // otherwise fall back to the toArray() output.
            if ($entity instanceof FieldableInterface) {
                $value = $entity->get($field);
            } else {
                $value = $values[$field] ?? null;
            }

            $fieldViolations = $this->validator->validate($value, $fieldConstraints);

            // Re-map violations to include the field path.
            foreach ($fieldViolations as $violation) {
                $violations->add(new \Symfony\Component\Validator\ConstraintViolation(
                    message: $violation->getMessage(),
                    messageTemplate: $violation->getMessageTemplate(),
                    parameters: $violation->getParameters(),
                    root: $entity,
                    propertyPath: $field . ($violation->getPropertyPath() !== '' ? '.' . $violation->getPropertyPath() : ''),
                    invalidValue: $violation->getInvalidValue(),
                    plural: $violation->getPlural(),
                    code: $violation->getCode(),
                    constraint: $violation->getConstraint(),
                    cause: $violation->getCause(),
                ));
            }
        }

        return $violations;
    }
}
