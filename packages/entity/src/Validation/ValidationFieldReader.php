<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\AtLeastOneOf;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Entity\EntityBase;

/** Closed framework-only validator for non-Public values. @api */
final class ValidationFieldReader
{
    /** @var \Closure(EntityBase, string): mixed */
    private readonly \Closure $obtain;

    public function __construct(private readonly ValidationReadLedgerInterface $ledger)
    {
        $obtain = \Closure::bind(
            static function (EntityBase $entity, string $field): mixed {
                $values = $entity->valueContainer->rawValues();
                $raw = $values[$field] ?? null;

                return isset($entity->casts[$field])
                    ? $entity->valueCaster()->castIn($field, $raw, $entity->casts[$field])
                    : $raw;
            },
            null,
            EntityBase::class,
        );
        $this->obtain = $obtain;
    }

    /**
     * @param list<Constraint> $constraints
     */
    public function validate(EntityBase $entity, string $field, array $constraints): ConstraintViolationListInterface
    {
        $this->assertClosedConstraints($field, $constraints);
        $reservation = $this->ledger->reserve($entity->entityStructure(), $field);
        try {
            $value = ($this->obtain)($entity, $field);
            $raw = Validation::createValidator()->validate($value, $constraints);
            $violations = new ConstraintViolationList();
            foreach ($raw as $violation) {
                $violations->add(new ConstraintViolation(
                    message: 'The non-Public field value is invalid.',
                    messageTemplate: 'The non-Public field value is invalid.',
                    parameters: [],
                    root: $entity->entityStructure(),
                    propertyPath: $field . ($violation->getPropertyPath() !== '' ? '.' . $violation->getPropertyPath() : ''),
                    invalidValue: RedactedInvalidValue::Value,
                    plural: null,
                    code: $violation->getCode(),
                    constraint: null,
                    cause: null,
                ));
            }
            $reservation->finalize(true);

            return $violations;
        } catch (\Throwable $exception) {
            $reservation->finalize(false);
            throw $exception;
        }
    }

    /** @param list<Constraint> $constraints */
    private function assertClosedConstraints(string $field, array $constraints): void
    {
        foreach ($constraints as $constraint) {
            if (!$constraint instanceof NotBlank
                && !$constraint instanceof NotNull
                && !$constraint instanceof Choice
                && !$constraint instanceof Email
                && !$constraint instanceof Length
                && !$constraint instanceof Range
                && !$constraint instanceof Type
                && !$constraint instanceof AtLeastOneOf) {
                throw new \LogicException(sprintf(
                    'Non-Public field %s uses custom constraint %s; only the closed framework validator set is permitted.',
                    $field,
                    $constraint::class,
                ));
            }
            if ($constraint instanceof AtLeastOneOf) {
                $this->assertClosedConstraints($field, $constraint->constraints);
            }
        }
    }
}
