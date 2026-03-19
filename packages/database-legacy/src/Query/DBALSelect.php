<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Waaseyaa\Database\SelectInterface;

final class DBALSelect implements SelectInterface
{
    private readonly QueryBuilder $qb;

    private string $tableAlias;

    private bool $isCountQuery = false;

    private bool $hasExplicitFields = false;

    public function __construct(
        Connection $connection,
        string $table,
        string $alias = '',
    ) {
        $this->qb = $connection->createQueryBuilder();
        $this->tableAlias = $alias !== '' ? $alias : $table;
        $this->qb->from($connection->quoteIdentifier($table), $this->tableAlias);
    }

    public function fields(string $tableAlias, array $fields = []): static
    {
        $this->hasExplicitFields = true;

        if (empty($fields)) {
            $this->qb->addSelect($tableAlias . '.*');
        } else {
            foreach ($fields as $field) {
                $this->qb->addSelect($tableAlias . '.' . $field);
            }
        }

        return $this;
    }

    public function addField(string $tableAlias, string $field, string $alias = ''): static
    {
        $this->hasExplicitFields = true;

        $col = $tableAlias . '.' . $field;
        if ($alias !== '') {
            $col .= ' AS ' . $alias;
        }
        $this->qb->addSelect($col);

        return $this;
    }

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $operator = strtoupper($operator);

        if ($operator === 'IS NULL') {
            $this->qb->andWhere($field . ' IS NULL');
        } elseif ($operator === 'IS NOT NULL') {
            $this->qb->andWhere($field . ' IS NOT NULL');
        } elseif ($operator === 'IN' || $operator === 'NOT IN') {
            $placeholder = $this->qb->createNamedParameter(
                $value,
                ArrayParameterType::STRING,
            );
            $this->qb->andWhere($field . ' ' . $operator . ' (' . $placeholder . ')');
        } elseif ($operator === 'BETWEEN') {
            if (!is_array($value) || count($value) !== 2) {
                throw new \InvalidArgumentException('BETWEEN operator requires an array of exactly 2 values.');
            }
            $p1 = $this->qb->createNamedParameter($value[0]);
            $p2 = $this->qb->createNamedParameter($value[1]);
            $this->qb->andWhere($field . ' BETWEEN ' . $p1 . ' AND ' . $p2);
        } elseif ($operator === 'LIKE' || $operator === 'NOT LIKE') {
            $placeholder = $this->qb->createNamedParameter($value);
            $this->qb->andWhere($field . ' ' . $operator . ' ' . $placeholder . " ESCAPE '\\'");
        } else {
            $placeholder = $this->qb->createNamedParameter($value);
            $this->qb->andWhere($field . ' ' . $operator . ' ' . $placeholder);
        }

        return $this;
    }

    public function isNull(string $field): static
    {
        $this->qb->andWhere($field . ' IS NULL');

        return $this;
    }

    public function isNotNull(string $field): static
    {
        $this->qb->andWhere($field . ' IS NOT NULL');

        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): static
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }

        $this->qb->addOrderBy($field, $direction);

        return $this;
    }

    public function range(int $offset, int $limit): static
    {
        $this->qb->setFirstResult($offset)->setMaxResults($limit);

        return $this;
    }

    public function join(string $table, string $alias, string $condition): static
    {
        $this->qb->innerJoin($this->tableAlias, $table, $alias, $condition);

        return $this;
    }

    public function leftJoin(string $table, string $alias, string $condition): static
    {
        $this->qb->leftJoin($this->tableAlias, $table, $alias, $condition);

        return $this;
    }

    public function countQuery(): static
    {
        $clone = clone $this;
        $clone->isCountQuery = true;

        // Reset order by on the cloned query builder.
        $clone->qb->resetOrderBy();
        $clone->qb->select('COUNT(*) AS count');

        return $clone;
    }

    public function execute(): \Traversable
    {
        // Default to SELECT * if no fields were explicitly set and not a count query.
        if (!$this->hasExplicitFields && !$this->isCountQuery) {
            $this->qb->select('*');
        }

        $result = $this->qb->executeQuery();

        while ($row = $result->fetchAssociative()) {
            yield $row;
        }
    }
}
