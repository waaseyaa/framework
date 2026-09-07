<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

use Waaseyaa\Entity\Repository\EntityIdentifierResolver;

/**
 * Closed callable used by the canonical entity-reference constraint builder.
 *
 * Unlike the public callback-shaped EntityExists API, this callable can only
 * perform an access-neutral lookup through the framework's final identifier
 * resolver. ValidationFieldReader admits this exact callable type before a
 * non-Public value leaves the closed reader.
 *
 * @internal
 */
final class EntityReferenceExistenceChecker
{
    public function __construct(
        private readonly EntityIdentifierResolver $resolver,
        private readonly string $targetType,
        private readonly bool $singleCardinality,
    ) {}

    public function __invoke(mixed $value): bool
    {
        if ($this->singleCardinality && $value === []) {
            return true;
        }

        if (!is_int($value) && (!is_string($value) || $value === '')) {
            return false;
        }

        return $this->resolver->resolve($this->targetType, $value) !== null;
    }
}
