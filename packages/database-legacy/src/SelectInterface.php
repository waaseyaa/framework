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
     * $field CONTRACT: $field is a DEVELOPER-SUPPLIED raw SQL fragment — a column,
     * a qualified/pre-quoted identifier, or an expression (e.g. SqlEntityQuery
     * passes `json_extract(_data, '$.x')`). It is emitted VERBATIM and is NOT
     * quoted or sanitised, the same developer-supplied-only contract as the
     * join() ON-condition. NEVER pass user input as $field. (Auto-quoting $field
     * as an identifier is a deferred follow-up that will add raw-expression seams
     * and migrate SqlEntityQuery; until then $field stays a raw fragment so the
     * entity query engine's pre-quoted identifiers + json_extract expressions
     * keep working.)
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

    public function range(int $offset, int $limit): static;

    public function join(string $table, string $alias, string $condition): static;

    public function leftJoin(string $table, string $alias, string $condition): static;

    public function countQuery(): static;

    /** @return \Traversable */
    public function execute(): \Traversable;
}
