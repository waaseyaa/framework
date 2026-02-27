<?php

declare(strict_types=1);

namespace Aurora\Database\Query;

use Aurora\Database\InsertInterface;

final class PdoInsert implements InsertInterface
{
    /** @var string[] */
    private array $fields = [];

    /** @var list<array<string, mixed>> */
    private array $valuesSets = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table,
    ) {}

    public function fields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    public function values(array $values): static
    {
        $this->valuesSets[] = $values;

        return $this;
    }

    public function execute(): int|string
    {
        if (empty($this->fields) && !empty($this->valuesSets)) {
            // If fields() was not called but values() was, infer fields from the first value set keys.
            $firstValues = $this->valuesSets[0];
            if (array_is_list($firstValues)) {
                throw new \RuntimeException('Cannot infer field names from indexed array. Call fields() before values().');
            }
            $this->fields = array_keys($firstValues);
        }

        if (empty($this->fields)) {
            throw new \RuntimeException('No fields specified for INSERT query.');
        }

        $columns = implode(', ', array_map(fn(string $col) => $this->quoteIdentifier($col), $this->fields));
        $placeholders = implode(', ', array_fill(0, count($this->fields), '?'));

        $sql = 'INSERT INTO ' . $this->quoteIdentifier($this->table) . ' (' . $columns . ') VALUES (' . $placeholders . ')';

        $stmt = $this->pdo->prepare($sql);

        foreach ($this->valuesSets as $values) {
            if (array_is_list($values)) {
                $params = $values;
            } else {
                // Map values by field order.
                $params = [];
                foreach ($this->fields as $field) {
                    $params[] = $values[$field] ?? null;
                }
            }
            $stmt->execute($params);
        }

        return $this->pdo->lastInsertId();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
