<?php

declare(strict_types=1);

namespace Aurora\Database\Query;

use Aurora\Database\SelectInterface;

final class PdoSelect implements SelectInterface
{
    private string $table;
    private string $tableAlias;

    /** @var array<int, array{tableAlias: string, fields: string[]}> */
    private array $fieldSets = [];

    /** @var array<int, array{tableAlias: string, field: string, alias: string}> */
    private array $addedFields = [];

    /** @var array<int, array{field: string, value: mixed, operator: string}> */
    private array $conditions = [];

    /** @var array<int, array{type: string, table: string, alias: string, condition: string}> */
    private array $joins = [];

    /** @var array<int, array{field: string, direction: string}> */
    private array $orderBys = [];

    private ?int $rangeOffset = null;
    private ?int $rangeLimit = null;
    private bool $isCountQuery = false;

    /** @var list<mixed> */
    private array $params = [];

    public function __construct(
        private readonly \PDO $pdo,
        string $table,
        string $alias = '',
    ) {
        $this->table = $table;
        $this->tableAlias = $alias !== '' ? $alias : $table;
    }

    public function fields(string $tableAlias, array $fields = []): static
    {
        $this->fieldSets[] = [
            'tableAlias' => $tableAlias,
            'fields' => $fields,
        ];

        return $this;
    }

    public function addField(string $tableAlias, string $field, string $alias = ''): static
    {
        $this->addedFields[] = [
            'tableAlias' => $tableAlias,
            'field' => $field,
            'alias' => $alias,
        ];

        return $this;
    }

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => $value,
            'operator' => strtoupper($operator),
        ];

        return $this;
    }

    public function isNull(string $field): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => null,
            'operator' => 'IS NULL',
        ];

        return $this;
    }

    public function isNotNull(string $field): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => null,
            'operator' => 'IS NOT NULL',
        ];

        return $this;
    }

    public function join(string $table, string $alias, string $condition): static
    {
        $this->joins[] = [
            'type' => 'INNER',
            'table' => $table,
            'alias' => $alias,
            'condition' => $condition,
        ];

        return $this;
    }

    public function leftJoin(string $table, string $alias, string $condition): static
    {
        $this->joins[] = [
            'type' => 'LEFT',
            'table' => $table,
            'alias' => $alias,
            'condition' => $condition,
        ];

        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }

        $this->orderBys[] = [
            'field' => $field,
            'direction' => $direction,
        ];

        return $this;
    }

    public function range(int $offset, int $limit): static
    {
        $this->rangeOffset = $offset;
        $this->rangeLimit = $limit;

        return $this;
    }

    public function countQuery(): static
    {
        $clone = clone $this;
        $clone->isCountQuery = true;
        $clone->orderBys = [];

        return $clone;
    }

    public function execute(): \Traversable
    {
        $this->params = [];
        $sql = $this->buildSql();

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);

        return $stmt;
    }

    private function buildSql(): string
    {
        $sql = 'SELECT ';

        if ($this->isCountQuery) {
            $sql .= 'COUNT(*) AS count';
        } else {
            $sql .= $this->buildFieldList();
        }

        // FROM clause
        $sql .= ' FROM ' . $this->quoteIdentifier($this->table);
        if ($this->tableAlias !== $this->table) {
            $sql .= ' AS ' . $this->quoteIdentifier($this->tableAlias);
        }

        // JOINs
        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN '
                . $this->quoteIdentifier($join['table'])
                . ' AS ' . $this->quoteIdentifier($join['alias'])
                . ' ON ' . $join['condition'];
        }

        // WHERE
        $where = $this->buildConditions();
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        // ORDER BY
        if (!empty($this->orderBys)) {
            $orderParts = [];
            foreach ($this->orderBys as $orderBy) {
                $orderParts[] = $orderBy['field'] . ' ' . $orderBy['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        // LIMIT/OFFSET
        if ($this->rangeLimit !== null) {
            $sql .= ' LIMIT ' . $this->rangeLimit;
            if ($this->rangeOffset !== null && $this->rangeOffset > 0) {
                $sql .= ' OFFSET ' . $this->rangeOffset;
            }
        }

        return $sql;
    }

    private function buildFieldList(): string
    {
        $columns = [];

        foreach ($this->fieldSets as $fieldSet) {
            $alias = $fieldSet['tableAlias'];
            $fields = $fieldSet['fields'];

            if (empty($fields)) {
                $columns[] = $this->quoteIdentifier($alias) . '.*';
            } else {
                foreach ($fields as $field) {
                    $columns[] = $this->quoteIdentifier($alias) . '.' . $this->quoteIdentifier($field);
                }
            }
        }

        foreach ($this->addedFields as $addedField) {
            $col = $this->quoteIdentifier($addedField['tableAlias']) . '.' . $this->quoteIdentifier($addedField['field']);
            if ($addedField['alias'] !== '') {
                $col .= ' AS ' . $this->quoteIdentifier($addedField['alias']);
            }
            $columns[] = $col;
        }

        if (empty($columns)) {
            return '*';
        }

        return implode(', ', $columns);
    }

    private function buildConditions(): string
    {
        if (empty($this->conditions)) {
            return '';
        }

        $parts = [];
        foreach ($this->conditions as $condition) {
            $field = $condition['field'];
            $value = $condition['value'];
            $operator = $condition['operator'];

            if ($operator === 'IS NULL') {
                $parts[] = $field . ' IS NULL';
            } elseif ($operator === 'IS NOT NULL') {
                $parts[] = $field . ' IS NOT NULL';
            } elseif ($operator === 'IN' || $operator === 'NOT IN') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("IN operator requires an array value.");
                }
                $placeholders = implode(', ', array_fill(0, count($value), '?'));
                $parts[] = $field . ' ' . $operator . ' (' . $placeholders . ')';
                foreach ($value as $v) {
                    $this->params[] = $v;
                }
            } elseif ($operator === 'BETWEEN') {
                if (!is_array($value) || count($value) !== 2) {
                    throw new \InvalidArgumentException("BETWEEN operator requires an array of exactly 2 values.");
                }
                $parts[] = $field . ' BETWEEN ? AND ?';
                $this->params[] = $value[0];
                $this->params[] = $value[1];
            } else {
                $parts[] = $field . ' ' . $operator . ' ?';
                $this->params[] = $value;
            }
        }

        return implode(' AND ', $parts);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
