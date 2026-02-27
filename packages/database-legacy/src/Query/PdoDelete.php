<?php

declare(strict_types=1);

namespace Aurora\Database\Query;

use Aurora\Database\DeleteInterface;

final class PdoDelete implements DeleteInterface
{
    /** @var array<int, array{field: string, value: mixed, operator: string}> */
    private array $conditions = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table,
    ) {}

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => $value,
            'operator' => strtoupper($operator),
        ];

        return $this;
    }

    public function execute(): int
    {
        $params = [];

        $sql = 'DELETE FROM ' . $this->quoteIdentifier($this->table);

        $where = $this->buildConditions($params);
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @param list<mixed> $params Parameters array, passed by reference to append condition values.
     */
    private function buildConditions(array &$params): string
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
                    $params[] = $v;
                }
            } else {
                $parts[] = $field . ' ' . $operator . ' ?';
                $params[] = $value;
            }
        }

        return implode(' AND ', $parts);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
