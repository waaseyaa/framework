<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

/**
 * The SQL form a referenced entity-query field resolves to, plus enough shape
 * information for the query builder to route it through the right DBAL seam
 * (WP6).
 *
 * Two kinds:
 *  - **Identifier** — a bare column (`weight`) or qualified `table.field`
 *    (NOT pre-quoted). It goes through {@see SelectInterface::condition()} /
 *    `orderBy()` / `isNull()` / `isNotNull()`, which auto-quote it.
 *  - **Expression** — a SQL fragment such as `json_extract(_data, '$.weight')`
 *    that cannot be quoted as an identifier. It goes through
 *    {@see SelectInterface::whereRaw()} / `orderByRaw()`, which emit verbatim.
 *
 * `isJsonExtract()` flags the `json_extract(...)` subset so the K3 native-type
 * casting path (CAST(... AS TEXT) for IN, mission #1257 WP05) can still be
 * applied around the expression.
 *
 * @internal Used only by SqlEntityQuery's SQL emission.
 */
final class ResolvedField
{
    private function __construct(
        private readonly string $sql,
        private readonly bool $isExpression,
        private readonly bool $isJsonExtract,
    ) {}

    /**
     * A bare/qualified identifier (NOT pre-quoted) — auto-quoted downstream.
     */
    public static function identifier(string $sql): self
    {
        return new self($sql, false, false);
    }

    /**
     * A raw SQL expression emitted verbatim through the whereRaw/orderByRaw
     * seams. Set $isJsonExtract when the expression is a `json_extract(...)`
     * call so the K3 CAST path can wrap it.
     */
    public static function expression(string $sql, bool $isJsonExtract = false): self
    {
        return new self($sql, true, $isJsonExtract);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    public function isExpression(): bool
    {
        return $this->isExpression;
    }

    public function isJsonExtract(): bool
    {
        return $this->isJsonExtract;
    }
}
