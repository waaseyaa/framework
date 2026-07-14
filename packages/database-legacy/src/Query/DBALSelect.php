<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Waaseyaa\Database\SelectInterface;

final class DBALSelect implements SelectInterface
{
    private readonly QueryBuilder $qb;

    private readonly Connection $connection;

    private string $tableAlias;

    private bool $isCountQuery = false;

    private bool $hasExplicitFields = false;

    public function __construct(
        Connection $connection,
        string $table,
        string $alias = '',
    ) {
        $this->connection = $connection;
        $this->qb = $connection->createQueryBuilder();
        $rawAlias = $alias !== '' ? $alias : $table;
        $this->tableAlias = $connection->quoteIdentifier($rawAlias);
        $this->qb->from($connection->quoteIdentifier($table), $this->tableAlias);
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function fields(string $tableAlias, array $fields = []): static
    {
        $this->hasExplicitFields = true;
        $quoted = $this->connection->quoteIdentifier($tableAlias);

        if (empty($fields)) {
            $this->qb->addSelect($quoted . '.*');
        } else {
            foreach ($fields as $field) {
                $this->qb->addSelect(
                    $field === '*' ? $quoted . '.*' : $quoted . '.' . $this->quoteName($field),
                );
            }
        }

        return $this;
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function addField(string $tableAlias, string $field, string $alias = ''): static
    {
        $this->hasExplicitFields = true;
        $quoted = $this->connection->quoteIdentifier($tableAlias);

        $col = $field === '*' ? $quoted . '.*' : $quoted . '.' . $this->quoteName($field);
        if ($alias !== '') {
            $col .= ' AS ' . $this->quoteName($alias);
        }
        $this->qb->addSelect($col);

        return $this;
    }

    /**
     * Quote an identifier (column / alias / table) for the active platform.
     *
     * Delegates to the platform's `quoteIdentifier`, which splits a qualified
     * `alias.column` on `.` and quotes each part — `"alias"."column"` (or
     * backticks per driver) — and doubles any embedded quote. A reserved word
     * (`key`, `count`, `order`, …) therefore works as an identifier, and a
     * value containing a quote / space / SQL metacharacter is rendered inert
     * (quoted) rather than concatenated raw. Cross-driver by construction.
     */
    private function quoteName(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $operator = strtoupper($operator);
        // CONTRACT (WP6): $field is an IDENTIFIER (column or qualified
        // `alias.column`) and is auto-quoted via quoteName() so a reserved-word /
        // metacharacter-bearing name is rendered inert. A SQL *expression* (e.g.
        // SqlEntityQuery's `json_extract(...)`) must go through whereRaw(), which
        // emits verbatim — quoting an expression would corrupt it. The $value
        // side is always bound as a parameter, so injection is impossible via
        // $value. See SelectInterface::condition() / ::whereRaw().
        $field = $this->quoteName($field);

        if ($operator === 'IS NULL') {
            $this->qb->andWhere($field . ' IS NULL');
        } elseif ($operator === 'IS NOT NULL') {
            $this->qb->andWhere($field . ' IS NOT NULL');
        } elseif ($operator === 'IN' || $operator === 'NOT IN') {
            if (!is_array($value)) {
                throw new \InvalidArgumentException('IN operator requires an array value.');
            }
            $placeholder = $this->qb->createNamedParameter(
                $value,
                ParameterTypeInferrer::array($value),
            );
            $this->qb->andWhere($field . ' ' . $operator . ' (' . $placeholder . ')');
        } elseif ($operator === 'BETWEEN') {
            if (!is_array($value) || count($value) !== 2) {
                throw new \InvalidArgumentException('BETWEEN operator requires an array of exactly 2 values.');
            }
            $p1 = $this->qb->createNamedParameter($value[0], ParameterTypeInferrer::scalar($value[0]));
            $p2 = $this->qb->createNamedParameter($value[1], ParameterTypeInferrer::scalar($value[1]));
            $this->qb->andWhere($field . ' BETWEEN ' . $p1 . ' AND ' . $p2);
        } elseif ($operator === 'LIKE' || $operator === 'NOT LIKE') {
            // $value is treated as a complete LIKE pattern (caller owns wildcards).
            // Bind it verbatim and declare backslash as the escape character so a
            // caller's str_replace(['%','_'],['\\%','\\_'],$input) actually escapes
            // literal wildcards. We deliberately do NOT escape $value here: doing so
            // would forbid wildcards entirely and double-escape callers that already
            // escape (e.g. SqlColumnQueryTranslator CONTAINS/STARTS_WITH). See
            // SelectInterface::condition() for the contract.
            $placeholder = $this->qb->createNamedParameter($value);
            $this->qb->andWhere($field . ' ' . $operator . ' ' . $placeholder . " ESCAPE '\\'");
        } else {
            $placeholder = $this->qb->createNamedParameter($value, ParameterTypeInferrer::scalar($value));
            $this->qb->andWhere($field . ' ' . $operator . ' ' . $placeholder);
        }

        return $this;
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function isNull(string $field): static
    {
        // $field is an IDENTIFIER → auto-quoted (WP6). Use whereRaw($expr.' IS
        // NULL') for an expression.
        $this->qb->andWhere($this->quoteName($field) . ' IS NULL');

        return $this;
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function isNotNull(string $field): static
    {
        // $field is an IDENTIFIER → auto-quoted (WP6). Use whereRaw($expr.' IS
        // NOT NULL') for an expression.
        $this->qb->andWhere($this->quoteName($field) . ' IS NOT NULL');

        return $this;
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function orderBy(string $field, string $direction = 'ASC'): static
    {
        $direction = $this->normaliseDirection($direction);

        // CONTRACT (WP6): like condition(), $field is an IDENTIFIER (column /
        // qualified `alias.column`) and is auto-quoted via quoteName(). A sort
        // *expression* (e.g. SqlEntityQuery's `json_extract(...)`) must go
        // through orderByRaw(), which emits verbatim.
        $this->qb->addOrderBy($this->quoteName($field), $direction);

        return $this;
    }

    /**
     * Add a raw WHERE expression, emitted VERBATIM. Each `?` in $expression is
     * replaced, left-to-right, with a bound placeholder for the corresponding
     * entry in $parameters (an array entry binds as a multi-value IN list). See
     * SelectInterface::whereRaw() for the developer-supplied-only contract.
     *
     * @param list<mixed> $parameters
     */
    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function whereRaw(string $expression, array $parameters = []): static
    {
        $index = 0;

        $sql = preg_replace_callback(
            '/\?/',
            function () use (&$index, $parameters): string {
                if (!\array_key_exists($index, $parameters)) {
                    throw new \InvalidArgumentException(
                        'whereRaw(): more `?` placeholders than bound parameters.',
                    );
                }
                $value = $parameters[$index];
                ++$index;
                $type = is_array($value)
                    ? ParameterTypeInferrer::array($value)
                    : ParameterTypeInferrer::scalar($value);

                return $this->qb->createNamedParameter($value, $type);
            },
            $expression,
        );

        if ($sql === null) {
            throw new \InvalidArgumentException('whereRaw(): failed to parse expression.');
        }

        if ($index !== count($parameters)) {
            throw new \InvalidArgumentException(
                'whereRaw(): more bound parameters than `?` placeholders.',
            );
        }

        $this->qb->andWhere($sql);

        return $this;
    }

    /**
     * Add a raw ORDER BY expression, emitted VERBATIM. See
     * SelectInterface::orderByRaw() for the developer-supplied-only contract.
     */
    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function orderByRaw(string $expression, string $direction): static
    {
        $this->qb->addOrderBy($expression, $this->normaliseDirection($direction));

        return $this;
    }

    private function normaliseDirection(string $direction): string
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }

        return $direction;
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function range(int $offset, int $limit): static
    {
        $this->qb->setFirstResult($offset)->setMaxResults($limit);

        return $this;
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function join(string $table, string $alias, string $condition): static
    {
        // $table and $alias are identifiers → quoted. $condition is a free-form
        // ON expression that cannot be blindly quoted (it references columns,
        // operators, literals); it is developer-supplied-only — NEVER user input —
        // matching the value-binding contract documented on condition().
        $this->qb->innerJoin($this->tableAlias, $this->quoteName($table), $this->quoteName($alias), $condition);

        return $this;
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
    public function leftJoin(string $table, string $alias, string $condition): static
    {
        // See join(): identifiers quoted; the ON $condition is developer-supplied-only.
        $this->qb->leftJoin($this->tableAlias, $this->quoteName($table), $this->quoteName($alias), $condition);

        return $this;
    }

    #[\NoDiscard('fluent builder — chain or assign the return value')]
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
