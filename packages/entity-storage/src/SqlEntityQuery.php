<?php

declare(strict_types=1);

namespace Aurora\EntityStorage;

use Aurora\Database\DatabaseInterface;
use Aurora\Entity\EntityTypeInterface;
use Aurora\Entity\Storage\EntityQueryInterface;

/**
 * SQL-based entity query implementation.
 *
 * Wraps the database select query builder to provide entity-level
 * querying with conditions, sorting, ranges, and counting.
 */
final class SqlEntityQuery implements EntityQueryInterface
{
    private readonly string $tableName;
    private readonly string $idKey;

    /** @var array<int, array{field: string, value: mixed, operator: string}> */
    private array $conditions = [];

    /** @var array<int, array{field: string, direction: string}> */
    private array $sorts = [];

    private ?int $rangeOffset = null;
    private ?int $rangeLimit = null;
    private bool $isCount = false;

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly DatabaseInterface $database,
    ) {
        $this->tableName = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $this->idKey = $keys['id'] ?? 'id';
    }

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => $value,
            'operator' => $operator,
        ];

        return $this;
    }

    public function exists(string $field): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => null,
            'operator' => 'IS NOT NULL',
        ];

        return $this;
    }

    public function notExists(string $field): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => null,
            'operator' => 'IS NULL',
        ];

        return $this;
    }

    public function sort(string $field, string $direction = 'ASC'): static
    {
        $this->sorts[] = [
            'field' => $field,
            'direction' => strtoupper($direction),
        ];

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
        // No-op in v0.1.0 — access checking is not implemented yet.
        return $this;
    }

    /**
     * Execute the query and return entity IDs.
     *
     * When count() has been called, returns a single-element array with the count.
     *
     * @return array<int|string>
     */
    public function execute(): array
    {
        $select = $this->database->select($this->tableName);

        if ($this->isCount) {
            $select = $select->countQuery();
        } else {
            $select->addField($this->tableName, $this->idKey);
        }

        // Apply conditions.
        foreach ($this->conditions as $condition) {
            $operator = strtoupper($condition['operator']);

            if ($operator === 'IS NULL') {
                $select->isNull($condition['field']);
            } elseif ($operator === 'IS NOT NULL') {
                $select->isNotNull($condition['field']);
            } else {
                $select->condition($condition['field'], $condition['value'], $condition['operator']);
            }
        }

        // Apply sorts.
        foreach ($this->sorts as $sort) {
            $select->orderBy($sort['field'], $sort['direction']);
        }

        // Apply range.
        if ($this->rangeLimit !== null) {
            $select->range($this->rangeOffset ?? 0, $this->rangeLimit);
        }

        $result = $select->execute();

        if ($this->isCount) {
            foreach ($result as $row) {
                $row = (array) $row;
                return [(int) ($row['count'] ?? 0)];
            }
            return [0];
        }

        $ids = [];
        foreach ($result as $row) {
            $row = (array) $row;
            $id = $row[$this->idKey];
            // Preserve integer IDs as integers.
            if (is_numeric($id) && (int) $id == $id) {
                $id = (int) $id;
            }
            $ids[] = $id;
        }

        return $ids;
    }
}
