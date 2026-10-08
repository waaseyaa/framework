<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

use InvalidArgumentException;

/**
 * Immutable filter declaration.
 *
 * Construction-time invariants (operator-value-shape matrix) are enforced
 * here; field-existence and storage-backend-supports-query checks are
 * deferred to {@code ListingDefinitionValidator} (WP10).
 *
 * @api
 */
final readonly class FilterDefinition
{
    private const EXPOSED_PARAM_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        public string $field,
        public Operator $op,
        public mixed $value,
        public ?string $exposedParam = null,
    ) {
        if ($this->field === '') {
            throw new InvalidArgumentException('FilterDefinition: $field must be non-empty.');
        }
        if ($this->exposedParam !== null && preg_match(self::EXPOSED_PARAM_PATTERN, $this->exposedParam) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'FilterDefinition: $exposedParam "%s" must match %s.',
                    $this->exposedParam,
                    self::EXPOSED_PARAM_PATTERN,
                ),
            );
        }
        $this->validateOperatorValueShape();
    }

    /**
     * Return a clone of this definition with $exposedParam set to $param.
     */
    public function withExposed(string $param): self
    {
        return new self($this->field, $this->op, $this->value, $param);
    }

    /**
     * Validate the operator-value matrix defined in
     * `docs/specs/listing-pipeline-v1.md`.
     */
    private function validateOperatorValueShape(): void
    {
        $value = $this->value;

        match ($this->op) {
            Operator::EQ, Operator::NEQ => $this->requireScalarOrNull($value),

            Operator::LT, Operator::LTE, Operator::GT, Operator::GTE => $this->requireScalarNonNull($value),

            Operator::IN, Operator::NOT_IN => $this->requireNonEmptyList($value),

            Operator::IS_NULL, Operator::IS_NOT_NULL => $this->requireNull($value),

            Operator::BETWEEN => $this->requireTwoElementTuple($value),

            Operator::STARTS_WITH, Operator::CONTAINS => $this->requireString($value),
        };
    }

    private function requireScalarOrNull(mixed $value): void
    {
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException(sprintf(
                'FilterDefinition: operator %s requires scalar or null value, got %s.',
                $this->op->value,
                get_debug_type($value),
            ));
        }
    }

    private function requireScalarNonNull(mixed $value): void
    {
        if ($value === null) {
            throw new InvalidArgumentException(sprintf(
                'FilterDefinition: operator %s requires non-null scalar value.',
                $this->op->value,
            ));
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException(sprintf(
                'FilterDefinition: operator %s requires scalar value, got %s.',
                $this->op->value,
                get_debug_type($value),
            ));
        }
    }

    private function requireNonEmptyList(mixed $value): void
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'FilterDefinition: operator %s requires array value, got %s.',
                $this->op->value,
                get_debug_type($value),
            ));
        }
        if ($value === []) {
            throw new InvalidArgumentException(sprintf(
                'FilterDefinition: operator %s requires non-empty array value (FR-010).',
                $this->op->value,
            ));
        }
        if (!array_is_list($value)) {
            throw new InvalidArgumentException(sprintf(
                'FilterDefinition: operator %s requires list-shaped array (sequential integer keys).',
                $this->op->value,
            ));
        }
    }

    private function requireNull(mixed $value): void
    {
        if ($value !== null) {
            throw new InvalidArgumentException(sprintf(
                'FilterDefinition: operator %s requires null value, got %s.',
                $this->op->value,
                get_debug_type($value),
            ));
        }
    }

    private function requireTwoElementTuple(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
            throw new InvalidArgumentException(sprintf(
                'FilterDefinition: operator %s requires a 2-element list [low, high].',
                $this->op->value,
            ));
        }
    }

    private function requireString(mixed $value): void
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'FilterDefinition: operator %s requires string value, got %s.',
                $this->op->value,
                get_debug_type($value),
            ));
        }
    }
}
