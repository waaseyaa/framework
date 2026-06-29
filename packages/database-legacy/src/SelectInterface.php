<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

/**
 * @api
 */
interface SelectInterface
{
    public function fields(string $tableAlias, array $fields = []): static;

    public function addField(string $tableAlias, string $field, string $alias = ''): static;

    /**
     * Add a WHERE condition. The value is always bound as a parameter (never
     * interpolated), so SQL injection is not possible via $value.
     *
     * $field CONTRACT (WP6): $field is an IDENTIFIER — a column or qualified
     * `alias.column`. It is auto-quoted via the platform's `quoteIdentifier`
     * (a reserved word / metacharacter-bearing name is rendered inert), so a
     * caller may pass a raw, unquoted column name safely. Pass a SQL
     * *expression* (e.g. `json_extract(_data, '$.x')`, `COALESCE(a, 0)`) via
     * {@see whereRaw()} instead — quoting an expression would corrupt it.
     *
     * LIKE / NOT LIKE contract: $value is treated as a complete LIKE *pattern*.
     * The caller is responsible for (a) adding any `%` / `_` wildcards it wants
     * and (b) escaping literal `%` / `_` in untrusted input with a backslash,
     * e.g. `str_replace(['%', '_'], ['\\%', '\\_'], $userInput)`. The
     * implementation appends `ESCAPE '\'` so that backslash-escaping takes
     * effect; it does NOT auto-escape $value, because that would make passing
     * wildcards impossible and double-escape callers that already escape.
     */
    public function condition(string $field, mixed $value, string $operator = '='): static;

    public function isNull(string $field): static;

    public function isNotNull(string $field): static;

    public function orderBy(string $field, string $direction = 'ASC'): static;

    /**
     * Add a raw WHERE expression, emitted VERBATIM, with positional `?`
     * parameters bound in order.
     *
     * Use this seam for SQL fragments that are NOT plain identifiers and so
     * cannot be auto-quoted by {@see condition()} — e.g. `json_extract(_data,
     * '$.x') = ?`, `COALESCE(reserved_at, 0) <= ?`, or a CAST wrapper. Each `?`
     * in $expression is replaced, left-to-right, by a bound placeholder for the
     * corresponding entry in $parameters (an array entry binds as a multi-value
     * IN list).
     *
     * $expression CONTRACT: this is the same DEVELOPER-SUPPLIED-ONLY contract as
     * the {@see join()} ON-condition — it is emitted verbatim and is NEVER
     * sanitised. NEVER interpolate user input into $expression; bind it through
     * a `?` placeholder + $parameters instead.
     *
     * @param list<mixed> $parameters
     *
     * @api
     */
    public function whereRaw(string $expression, array $parameters = []): static;

    /**
     * Add a raw ORDER BY expression, emitted VERBATIM.
     *
     * Companion to {@see orderBy()} for expressions that cannot be auto-quoted
     * as an identifier (e.g. `json_extract(_data, '$.weight')`). Same
     * DEVELOPER-SUPPLIED-ONLY contract as {@see whereRaw()} — NEVER pass user
     * input.
     *
     * @api
     */
    public function orderByRaw(string $expression, string $direction): static;

    public function range(int $offset, int $limit): static;

    public function join(string $table, string $alias, string $condition): static;

    public function leftJoin(string $table, string $alias, string $condition): static;

    public function countQuery(): static;

    /** @return \Traversable */
    public function execute(): \Traversable;
}
