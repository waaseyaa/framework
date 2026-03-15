<?php

declare(strict_types=1);

namespace Waaseyaa\Validation;

use Symfony\Component\Validator\Constraints\Length;
use Waaseyaa\Validation\Constraint\AllowedValues;
use Waaseyaa\Validation\Constraint\EntityExists;
use Waaseyaa\Validation\Constraint\NotEmpty;
use Waaseyaa\Validation\Constraint\SafeMarkup;
use Waaseyaa\Validation\Constraint\UniqueField;

/**
 * Factory for creating common validation constraints.
 *
 * Provides a fluent, readable API for constructing constraints
 * without needing to import individual constraint classes.
 */
final class ConstraintFactory
{
    /**
     * Create a NotEmpty constraint (value must not be null, empty string, or empty array).
     */
    public static function required(?string $message = null): NotEmpty
    {
        return new NotEmpty(message: $message);
    }

    /**
     * Create a max-length constraint using Symfony's Length constraint.
     */
    public static function maxLength(int $length, ?string $message = null): Length
    {
        return new Length(
            max: $length,
            maxMessage: $message ?? 'This value is too long. It should have {{ limit }} character or less.|This value is too long. It should have {{ limit }} characters or less.',
        );
    }

    /**
     * Create an AllowedValues constraint.
     *
     * @param array<int, mixed> $values The list of valid values.
     * @param bool $strict Whether to use strict comparison (default true).
     */
    public static function allowedValues(array $values, bool $strict = true, ?string $message = null): AllowedValues
    {
        return new AllowedValues(values: $values, strict: $strict, message: $message);
    }

    /**
     * Create a UniqueField constraint with a callback checker.
     *
     * @param string $entityType The entity type machine name.
     * @param string $field The field machine name.
     * @param callable(mixed): bool $checker Returns true if value already exists.
     */
    public static function unique(string $entityType, string $field, callable $checker, ?string $message = null): UniqueField
    {
        return new UniqueField(
            entityTypeId: $entityType,
            fieldName: $field,
            existsChecker: $checker,
            message: $message,
        );
    }

    /**
     * Create a SafeMarkup constraint.
     *
     * @param string[] $allowedTags List of allowed HTML tags (without angle brackets).
     */
    public static function safeMarkup(array $allowedTags = SafeMarkup::DEFAULT_ALLOWED_TAGS, ?string $message = null): SafeMarkup
    {
        return new SafeMarkup(allowedTags: $allowedTags, message: $message);
    }

    /**
     * Create an EntityExists constraint with a callback checker.
     *
     * @param string $entityType The entity type being referenced.
     * @param callable(mixed): bool $checker Returns true if entity exists.
     */
    public static function entityExists(string $entityType, callable $checker, ?string $message = null): EntityExists
    {
        return new EntityExists(
            entityTypeId: $entityType,
            existsChecker: $checker,
            message: $message,
        );
    }
}
