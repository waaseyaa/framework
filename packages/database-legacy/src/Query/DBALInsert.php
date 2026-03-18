<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Query;

use Doctrine\DBAL\Connection;
use Waaseyaa\Database\InsertInterface;

final class DBALInsert implements InsertInterface
{
    /** @var string[] */
    private array $fields = [];

    /** @var list<array<int|string, mixed>> */
    private array $valuesSets = [];

    public function __construct(
        private readonly Connection $connection,
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

        foreach ($this->valuesSets as $values) {
            $params = [];
            if (array_is_list($values)) {
                foreach ($this->fields as $i => $field) {
                    $params[$field] = $values[$i] ?? null;
                }
            } else {
                foreach ($this->fields as $field) {
                    $params[$field] = $values[$field] ?? null;
                }
            }
            $this->connection->insert($this->table, $params);
        }

        return $this->connection->lastInsertId();
    }
}
