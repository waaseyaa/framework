<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Validates entity field values against provided constraints.
 *
 * The EntityValidator takes a Symfony ValidatorInterface and applies
 * per-field constraints to values from {@see EntityInterface::get()} (cast-aware),
 * collecting all violations across all fields into a single violation list.
 */
final class EntityValidator
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly ?ValidationFieldReader $closedReader = null,
    ) {}

    /**
     * Create a validator backed by Symfony's default validator.
     *
     * Intended for kernel wiring (research D1): the instance is stateless and
     * safe to share across all repositories. Deliberately not cached statically
     * — the kernel owns sharing.
     */
    public static function createDefault(): self
    {
        return new self(\Symfony\Component\Validator\Validation::createValidator());
    }

    /**
     * Validate an entity's values against the provided constraints.
     *
     * Values are read via {@see EntityInterface::get()}, which is part of the core
     * entity contract (not limited to {@see \Waaseyaa\Entity\FieldableInterface}).
     * {@see \Waaseyaa\Entity\FieldableInterface} adds field metadata ({@see \Waaseyaa\Entity\FieldableInterface::hasField},
     * {@see \Waaseyaa\Entity\FieldableInterface::getFieldDefinitions}) on top of the same {@see EntityInterface::get()}.
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

        foreach ($constraints as $field => $fieldConstraints) {
            // Normalize single constraint to array.
            if (!is_array($fieldConstraints)) {
                $fieldConstraints = [$fieldConstraints];
            }

            if ($entity instanceof EntityBase && $entity->fieldReadLevel($field) !== FieldReadLevel::Public) {
                if ($this->closedReader === null) {
                    throw new \LogicException(sprintf('Non-Public field %s requires the closed validation reader.', $field));
                }
                $violations->addAll($this->closedReader->validate($entity, $field, $fieldConstraints));
                continue;
            }

            // EntityInterface::get() is the cast-aware boundary (#1181 ST-6); do not use
            // toArray() slices here or validation sees storage scalars instead of domain types.
            $value = $entity->get($field);

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
