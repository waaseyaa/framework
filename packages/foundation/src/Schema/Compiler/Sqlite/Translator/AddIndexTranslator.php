<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Schema\Compiler\Sqlite\Translator;

use Waaseyaa\Foundation\Schema\Compiler\Step\CreateIndex;
use Waaseyaa\Foundation\Schema\Diff\AddIndex;

/**
 * Translate {@see AddIndex} into a SQLite CREATE INDEX step.
 *
 * Output shape: `CREATE [UNIQUE] INDEX "<name>" ON "<table>" ("<c1>",
 * "<c2>", …)`.
 *
 * **Anonymous-index naming:** when {@see AddIndex::$name} is null the
 * compiler derives a deterministic name `idx_<table>_<col1>__<col2>…`.
 * The derived name is truncated at 63 characters (the conservative
 * cross-dialect identifier limit; SQLite itself imposes no limit, but
 * truncating here keeps the SQL portable to the future MySQL /
 * Postgres compilers without a renaming step). Truncation drops a
 * trailing partial join token cleanly to the last `__` boundary.
 *
 * Order of `$columns` is significant: `[a, b]` and `[b, a]` produce
 * indexes with different lookup characteristics and therefore different
 * derived names and `diff_hash`es.
 */
final class AddIndexTranslator
{
    private const MAX_IDENTIFIER_LENGTH = 63;

    public static function translate(AddIndex $op): CreateIndex
    {
        if ($op->columns === []) {
            throw new \InvalidArgumentException(
                'AddIndex requires at least one column on table ' . $op->table . '.',
            );
        }

        $name = self::resolveName($op);

        $unique = $op->unique ? 'UNIQUE ' : '';
        $quotedColumns = implode(', ', array_map(
            SqliteIdentifier::quote(...),
            $op->columns,
        ));

        $sql = sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $unique,
            SqliteIdentifier::quote($name),
            SqliteIdentifier::quote($op->table),
            $quotedColumns,
        );

        return new CreateIndex(
            table: $op->table,
            name: $name,
            columns: $op->columns,
            unique: $op->unique,
            sql: $sql,
        );
    }

    /**
     * @param list<string> $columns
     */
    /**
     * The index name this op will actually create.
     *
     * Public so runtime precondition checks resolve the same identifier the
     * compiler emits, instead of re-deriving it and drifting.
     */
    public static function resolveName(AddIndex $op): string
    {
        return $op->name ?? self::deriveName($op->table, $op->columns);
    }

    private static function deriveName(string $table, array $columns): string
    {
        $base = 'idx_' . $table . '_' . implode('__', $columns);

        if (strlen($base) <= self::MAX_IDENTIFIER_LENGTH) {
            return $base;
        }

        return substr($base, 0, self::MAX_IDENTIFIER_LENGTH);
    }
}
