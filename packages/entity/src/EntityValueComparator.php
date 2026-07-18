<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Closed value-comparison authority. It returns names only; values and containers never cross it.
 *
 * @internal Exact framework comparison call sites are architecture-inventoried.
 */
final class EntityValueComparator
{
    /** @var \Closure(EntityBase, EntityBase, list<string>): list<string> */
    private readonly \Closure $changedFields;

    /** @var \Closure(EntityBase, array<string, mixed>, list<string>): list<string> */
    private readonly \Closure $matchingSubmittedFields;

    public function __construct()
    {
        $this->changedFields = \Closure::bind(
            static fn(EntityBase $current, EntityBase $target, array $fields): array =>
                $current->valueContainer->changedFieldNames($target->valueContainer, $fields),
            null,
            EntityBase::class,
        );
        $this->matchingSubmittedFields = \Closure::bind(
            static fn(EntityBase $current, array $submitted, array $fields): array =>
                $current->valueContainer->matchingSubmittedFieldNames($submitted, $fields),
            null,
            EntityBase::class,
        );
    }

    /** @param list<string> $targetFields @return list<string> */
    public function changedFieldNames(EntityBase $current, EntityBase $target, array $targetFields): array
    {
        return ($this->changedFields)($current, $target, $targetFields);
    }

    /** @param array<string, mixed> $submitted @param list<string> $fields @return list<string> */
    public function matchingSubmittedFieldNames(EntityBase $current, array $submitted, array $fields): array
    {
        return ($this->matchingSubmittedFields)($current, $submitted, $fields);
    }
}
