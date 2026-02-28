<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Fixtures;

use Aurora\Entity\EntityInterface;
use Aurora\Entity\Storage\EntityQueryInterface;

/**
 * In-memory entity query for testing.
 *
 * Supports actual filtering, sorting, and pagination when entities are provided.
 */
class InMemoryEntityQuery implements EntityQueryInterface
{
    /** @var array<int|string> */
    private array $entityIds;

    /** @var array<int|string, EntityInterface> */
    private array $entities;

    private bool $isCount = false;

    /** @var array{field: string, value: mixed, operator: string}[] */
    private array $conditions = [];

    /** @var array{field: string, direction: string}[] */
    private array $sorts = [];

    private ?int $rangeOffset = null;
    private ?int $rangeLimit = null;

    /**
     * @param array<int|string>                  $entityIds All available entity IDs.
     * @param array<int|string, EntityInterface>  $entities  Entity map for field-level filtering/sorting.
     */
    public function __construct(array $entityIds = [], array $entities = [])
    {
        $this->entityIds = $entityIds;
        $this->entities = $entities;
    }

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $this->conditions[] = ['field' => $field, 'value' => $value, 'operator' => $operator];
        return $this;
    }

    public function exists(string $field): static
    {
        return $this;
    }

    public function notExists(string $field): static
    {
        return $this;
    }

    public function sort(string $field, string $direction = 'ASC'): static
    {
        $this->sorts[] = ['field' => $field, 'direction' => $direction];
        return $this;
    }

    public function range(int $offset, int $limit): static
    {
        $this->rangeOffset = $offset;
        $this->rangeLimit = $limit;
        return $this;
    }

    public function count(): static
    {
        $this->isCount = true;
        return $this;
    }

    public function accessCheck(bool $check = true): static
    {
        return $this;
    }

    public function execute(): array
    {
        $ids = $this->entityIds;

        // Apply conditions if entities are available for field-level filtering.
        if ($this->conditions !== [] && $this->entities !== []) {
            $ids = array_filter($ids, function (int|string $id): bool {
                if (!isset($this->entities[$id])) {
                    return false;
                }
                $entity = $this->entities[$id];
                foreach ($this->conditions as $condition) {
                    $fieldValue = $this->getEntityFieldValue($entity, $condition['field']);
                    if (!$this->matchCondition($fieldValue, $condition['value'], $condition['operator'])) {
                        return false;
                    }
                }
                return true;
            });
            $ids = array_values($ids);
        }

        // Apply sorts if entities are available for field-level sorting.
        if ($this->sorts !== [] && $this->entities !== []) {
            usort($ids, function (int|string $a, int|string $b): int {
                foreach ($this->sorts as $sort) {
                    $entityA = $this->entities[$a] ?? null;
                    $entityB = $this->entities[$b] ?? null;
                    $valA = $entityA !== null ? $this->getEntityFieldValue($entityA, $sort['field']) : null;
                    $valB = $entityB !== null ? $this->getEntityFieldValue($entityB, $sort['field']) : null;
                    $cmp = $valA <=> $valB;
                    if ($sort['direction'] === 'DESC') {
                        $cmp = -$cmp;
                    }
                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }
                return 0;
            });
        }

        // Count before pagination if isCount is set.
        if ($this->isCount) {
            return [\count($ids)];
        }

        if ($this->rangeOffset !== null && $this->rangeLimit !== null) {
            $ids = array_slice($ids, $this->rangeOffset, $this->rangeLimit);
        }

        return $ids;
    }

    /**
     * Get a field value from an entity.
     */
    private function getEntityFieldValue(EntityInterface $entity, string $field): mixed
    {
        // Try get() method first (ContentEntityBase), then toArray() fallback.
        if (method_exists($entity, 'get')) {
            return $entity->get($field);
        }
        $values = $entity->toArray();
        return $values[$field] ?? null;
    }

    /**
     * Evaluate whether a field value matches a condition.
     */
    private function matchCondition(mixed $fieldValue, mixed $conditionValue, string $operator): bool
    {
        return match ($operator) {
            '=' => $fieldValue == $conditionValue,
            '!=' => $fieldValue != $conditionValue,
            '>' => $fieldValue > $conditionValue,
            '<' => $fieldValue < $conditionValue,
            '>=' => $fieldValue >= $conditionValue,
            '<=' => $fieldValue <= $conditionValue,
            'CONTAINS' => \is_string($fieldValue) && \is_string($conditionValue) && str_contains($fieldValue, $conditionValue),
            'STARTS_WITH' => \is_string($fieldValue) && \is_string($conditionValue) && str_starts_with($fieldValue, $conditionValue),
            default => false,
        };
    }
}
