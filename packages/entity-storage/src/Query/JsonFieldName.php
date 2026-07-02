<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Query;

/**
 * Guards the raw `json_extract(...)` interpolation sink against SQL
 * metacharacters in a field name.
 *
 * The SQL query engines ({@see \Waaseyaa\EntityStorage\SqlEntityQuery} and
 * {@see \Waaseyaa\EntityStorage\Driver\SqlStorageDriver}) resolve a field that
 * is not a real table column to a `json_extract(_data, '$.<field>')` expression
 * that is emitted verbatim through `whereRaw()`/`orderByRaw()`. Only the filter
 * *value* is ever bound as a `?` parameter — the JSON-path field *name* is baked
 * directly into the SQL text. A field name containing a single quote can break
 * out of the `'$.<field>'` string literal (SQL injection via an unvalidated
 * filter/sort field name — audit R2 WP1).
 *
 * This is the single canonical identifier guard both sinks call immediately
 * before interpolating a field name into a json_extract expression. Real entity
 * field/machine names are `[a-z0-9_]`, so the allowlist regex never rejects a
 * legitimate declared field while rejecting every SQL metacharacter (quotes,
 * parens, semicolons, whitespace, dots, dashes). It makes the raw fragment
 * un-injectable regardless of which caller (present or future) reaches the sink,
 * independent of any upstream API-layer allowlist.
 *
 * @api
 */
final class JsonFieldName
{
    /**
     * @throws \InvalidArgumentException If `$field` contains anything other than
     *   ASCII letters, digits, or underscores.
     */
    public static function assertQueryable(string $field): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $field) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid field name "%s": only letters, digits, and underscores are allowed in a queryable field name.',
                $field,
            ));
        }
    }
}
