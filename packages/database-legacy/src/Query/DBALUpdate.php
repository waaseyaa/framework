<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Query;

use Doctrine\DBAL\Connection;
use Waaseyaa\Database\UpdateInterface;

final class DBALUpdate implements UpdateInterface
{
    /** @var array<string, mixed> */
    private array $fields = [];

    /** @var array<int, array{field: string, value: mixed, operator: string}> */
    private array $conditions = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function fields(array $fields): static
    {
        $this->fields = $fields;

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

    public function execute(): int
    {
        if (empty($this->fields)) {
            throw new \RuntimeException('No fields specified for UPDATE query.');
        }

        if ($this->hasComplexConditions()) {
            return $this->executeWithQueryBuilder();
        }

        // Simple path: all conditions are '=' operators.
        return $this->connection->update($this->connection->quoteIdentifier($this->table), $this->fields, $this->simpleCriteria());
    }

    private function hasComplexConditions(): bool
    {
        foreach ($this->conditions as $cond) {
            if ($cond['operator'] !== '=') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleCriteria(): array
    {
        $criteria = [];
        foreach ($this->conditions as $cond) {
            $criteria[$cond['field']] = $cond['value'];
        }

        return $criteria;
    }

    private function executeWithQueryBuilder(): int
    {
        $qb = $this->connection->createQueryBuilder()->update($this->connection->quoteIdentifier($this->table));

        foreach ($this->fields as $col => $val) {
            $qb->set($col, $qb->createNamedParameter($val));
        }

        $this->applyConditions($qb);

        return $qb->executeStatement();
    }

    private function applyConditions(\Doctrine\DBAL\Query\QueryBuilder $qb): void
    {
        foreach ($this->conditions as $cond) {
            $field = $cond['field'];
            $value = $cond['value'];
            $operator = $cond['operator'];

            if ($operator === 'IS NULL') {
                $qb->andWhere($field . ' IS NULL');
            } elseif ($operator === 'IS NOT NULL') {
                $qb->andWhere($field . ' IS NOT NULL');
            } elseif ($operator === 'IN' || $operator === 'NOT IN') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException('IN operator requires an array value.');
                }
                $placeholder = $qb->createNamedParameter(
                    $value,
                    \Doctrine\DBAL\ArrayParameterType::STRING,
                );
                $qb->andWhere($field . ' ' . $operator . ' (' . $placeholder . ')');
            } else {
                $placeholder = $qb->createNamedParameter($value);
                $qb->andWhere($field . ' ' . $operator . ' ' . $placeholder);
            }
        }
    }
}
