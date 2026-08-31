<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

use Doctrine\DBAL\Connection;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\Translator\AddIndexTranslator;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\Translator\SqliteColumnType;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\Translator\SqliteIdentifier;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\AddIndex;
use Waaseyaa\Foundation\Schema\Diff\SchemaDiffOp;

/**
 * Decides, for one authored operation, whether the live database already
 * satisfies it exactly.
 *
 * This is the only place lifecycle awareness enters the v2 path. The authored
 * plan stays immutable and database-I/O-free; introspection happens here, in the
 * runtime, immediately before that operation would run.
 *
 * **Comparison is semantic, not textual.** The canonical entity-schema
 * materializer emits Doctrine's DDL vocabulary (`CLOB`, `DOUBLE PRECISION`,
 * `DEFAULT NULL`) while the v2 compiler emits its own (`TEXT`, `REAL`, no
 * default clause). Those are the *same column* in SQLite, so types are compared
 * by SQLite storage affinity and a literal `NULL` default is normalized to "no
 * default". Comparing rendered SQL text instead would report every
 * materializer-created column as incompatible and abort the run.
 *
 * **Deliberately narrow.** Only additive operations whose satisfaction can be
 * decided exactly participate: {@see AddColumn} and {@see AddIndex}. Every other
 * kind reports {@see OpPrecondition::NeedsApply} and is left to the compiler's
 * policy gates and to SQLite itself, so nothing is skipped on the strength of a
 * name. Renames and drops in particular are never treated as already satisfied:
 * an absent source is ambiguous, and guessing would risk silently accepting an
 * unrelated schema.
 *
 * **Fail closed.** An object that exists under the authored identity but does not
 * match the declared shape throws {@see IncompatibleSchemaStateException} rather
 * than being applied over or skipped.
 *
 * **The column comparison is closed over SQLite's constraint grammar.** The
 * authored vocabulary is {@see \Waaseyaa\Foundation\Schema\Diff\ColumnSpec}, and
 * the compiler renders only `<type> [NOT NULL] [DEFAULT <literal>]`, so any
 * other property of a live column is a real divergence. SQLite's
 * `column-constraint` and `table-constraint` productions are finite, which makes
 * that a decidable question rather than an open-ended one:
 *
 * | Property | Source |
 * |---|---|
 * | `PRIMARY KEY`, generated / hidden, type, nullability, default | `PRAGMA table_xinfo` |
 * | `UNIQUE` constraint membership | `PRAGMA index_list` (`origin` = `u`) + `index_info` |
 * | `REFERENCES` source membership | `PRAGMA foreign_key_list` |
 * | `COLLATE`, `NOT NULL` conflict policy, `CHECK` dependence | stored DDL, via {@see SqliteTableDefinition} |
 *
 * Catalogue is authority wherever a catalogue exists; only the last row has no
 * pragma. Because the production list is closed, reaching the end is a proof of
 * equivalence rather than an absence of findings.
 *
 * Three refusals are deliberately narrower than a blanket rule, because each has
 * a legitimate shape beside it that must still be accepted: an independently created
 * index (`origin` = `c` in `index_list`) is a separate schema object with its own
 * authored form, {@see AddIndex}, and never makes the column unauthorable; a
 * foreign key refuses only where this column is the **source**; and explicit
 * `ON CONFLICT ABORT` and `COLLATE BINARY` restate the defaults the compiler's
 * own output carries, so they are compared semantically and accepted.
 *
 * @see docs/change-records/FW-2701.md — C3 already satisfied, C4 fail closed
 */
final readonly class OpPreconditionResolver
{
    /**
     * Declared-type spellings that mean the same logical type as an authored
     * {@see \Waaseyaa\Foundation\Schema\Diff\ColumnSpec} token, but resolve to a
     * different SQLite affinity.
     *
     * This is deliberately an explicit, per-logical-type allowlist rather than a
     * blanket affinity widening. The canonical entity-schema materializer emits
     * Doctrine's `BOOLEAN` (NUMERIC affinity) for a boolean field while the v2
     * compiler renders `INTEGER` (INTEGER affinity); both mean "stores 0 or 1".
     * Equating INTEGER and NUMERIC generally would also accept genuinely
     * different shapes, so only the pairs below are reconciled.
     *
     * @var array<string, list<string>>
     */
    private const EQUIVALENT_DECLARED_TYPES = [
        'boolean' => ['BOOLEAN', 'TINYINT(1)', 'TINYINT'],
    ];

    /**
     * `PRAGMA table_xinfo.hidden`, in SQLite's own numbering.
     *
     * Zero is an ordinary column. Everything else is a column an authored
     * `AddColumn` cannot declare, and is named in the refusal so an operator can
     * tell a generated column from a virtual table's hidden one.
     *
     * @var array<int, string>
     */
    private const HIDDEN_COLUMN_KINDS = [
        1 => 'hidden',
        2 => 'VIRTUAL generated',
        3 => 'STORED generated',
    ];

    public function __construct(private Connection $connection) {}

    public function resolve(SchemaDiffOp $op): OpPrecondition
    {
        return match (true) {
            $op instanceof AddColumn => $this->resolveAddColumn($op),
            $op instanceof AddIndex => $this->resolveAddIndex($op),
            default => OpPrecondition::NeedsApply,
        };
    }

    private function resolveAddColumn(AddColumn $op): OpPrecondition
    {
        if (!$this->tableExists($op->table)) {
            return OpPrecondition::NeedsApply;
        }

        $live = null;
        foreach ($this->columns($op->table) as $column) {
            if (($column['name'] ?? null) === $op->column) {
                $live = $column;
                break;
            }
        }

        if ($live === null) {
            return OpPrecondition::NeedsApply;
        }

        // Structural properties first, and before type, nullability and default,
        // because they are the most consequential divergences and because a
        // primary key is the reason the nullability reading below cannot be
        // trusted for this column at all.
        //
        // A generated column is absent from PRAGMA table_info entirely, which is
        // why the whole comparison reads table_xinfo: without it this column
        // looked missing, the operation was applied, and SQLite refused it with a
        // raw error instead of an auditable refusal.
        $hidden = (int) ($live['hidden'] ?? 0);
        if ($hidden !== 0) {
            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                sprintf(
                    'declared a plain column, found a %s column',
                    self::HIDDEN_COLUMN_KINDS[$hidden] ?? 'hidden',
                ),
            );
        }

        // `pk` is a 1-based position within the key, not a flag: membership is
        // any non-zero value. Testing `pk === 1` would silently miss a column
        // sitting later in a composite key.
        $primaryKeyPosition = (int) ($live['pk'] ?? 0);
        if ($primaryKeyPosition !== 0) {
            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                sprintf(
                    'declared a plain column, found column %d of the table primary key',
                    $primaryKeyPosition,
                ),
            );
        }

        // `origin` separates a UNIQUE *constraint* of the table, which is part of
        // the column and unauthorable, from an independently created index,
        // which is a separate object AddIndex authors. EntitySchemaSync emits the
        // latter for entity `uuid` columns, so conflating them would refuse every
        // ordinary entity table.
        foreach ($this->indexes($op->table) as $index) {
            if ((string) ($index['origin'] ?? '') !== 'u') {
                continue;
            }
            $name = (string) ($index['name'] ?? '');
            if (!$this->indexCovers($name, $op->column)) {
                continue;
            }

            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                sprintf('declared a plain column, found a member of a UNIQUE constraint (index "%s")', $name),
            );
        }

        // Only the source side. Being the *target* of another table's foreign key
        // is a property of that other table, not of this column.
        foreach ($this->connection->fetchAllAssociative(
            sprintf('PRAGMA foreign_key_list(%s)', $this->quote($op->table)),
        ) as $foreignKey) {
            if (strcasecmp((string) ($foreignKey['from'] ?? ''), $op->column) !== 0) {
                continue;
            }

            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                sprintf(
                    'declared a plain column, found the source of a foreign key into "%s"("%s")',
                    (string) ($foreignKey['table'] ?? ''),
                    (string) ($foreignKey['to'] ?? ''),
                ),
            );
        }

        // The only reading with no catalogue behind it, and the one that
        // establishes whether the stored text is a column list we can model at
        // all — so it runs before the value comparisons, which are meaningless
        // for a column this reader cannot locate. `COLLATE`, a `NOT NULL`
        // conflict policy and a `CHECK` that can read this column exist solely
        // here, and each is unauthorable. An unreadable construct is returned as
        // a reason too, so unknown refuses rather than passing silently.
        $divergences = $this->definition($op->table)?->plainColumnDivergences($op->column)
            ?? ['its stored definition could not be retrieved'];
        if ($divergences !== []) {
            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                'declared a plain column, but ' . implode('; ', $divergences),
            );
        }

        $expectedType = SqliteColumnType::render($op->spec);
        $expectedAffinity = self::affinity($expectedType);
        $actualDeclared = trim((string) ($live['type'] ?? ''));
        $actualAffinity = self::affinity($actualDeclared);
        $reconciled = in_array(
            strtoupper($actualDeclared),
            self::EQUIVALENT_DECLARED_TYPES[$op->spec->type] ?? [],
            true,
        );
        if (!$reconciled && $actualAffinity !== $expectedAffinity) {
            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                sprintf(
                    'declared %s (%s affinity), found %s (%s affinity)',
                    $expectedType,
                    $expectedAffinity,
                    $actualDeclared === '' ? '(none)' : $actualDeclared,
                    $actualAffinity,
                ),
            );
        }

        $expectedNotNull = $op->spec->nullable ? 0 : 1;
        $actualNotNull = (int) ($live['notnull'] ?? 0);
        if ($actualNotNull !== $expectedNotNull) {
            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                sprintf(
                    'declared %s, found %s',
                    $op->spec->nullable ? 'NULL' : 'NOT NULL',
                    $actualNotNull === 1 ? 'NOT NULL' : 'NULL',
                ),
            );
        }

        // Both sides are compared as SQL literal expressions, produced by the
        // same renderer the compiler uses. Comparing an unquoted PHP string
        // against SQLite's literal text conflated two representations: it
        // refused an authored empty string, an authored "NULL", and any value
        // with surrounding whitespace or apostrophes, while accepting a column
        // that had no default at all.
        $actualDefault = self::normalizeActualDefault($live['dflt_value'] ?? null);
        $expectedDefault = $op->spec->default === null
            ? null
            : SqliteIdentifier::literal($op->spec->default);

        if ($actualDefault !== $expectedDefault) {
            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                sprintf(
                    'declared default %s, found %s',
                    $expectedDefault ?? 'none',
                    $actualDefault ?? 'none',
                ),
            );
        }

        return OpPrecondition::AlreadySatisfied;
    }

    private function resolveAddIndex(AddIndex $op): OpPrecondition
    {
        if (!$this->tableExists($op->table)) {
            return OpPrecondition::NeedsApply;
        }

        // Identity is the name the compiler will actually create, never "some
        // index that happens to cover the same columns". A differently-named
        // index, a SQLite auto-index, or a partial index must not satisfy this.
        $name = AddIndexTranslator::resolveName($op);

        $live = null;
        foreach ($this->indexes($op->table) as $index) {
            if ((string) ($index['name'] ?? '') === $name) {
                $live = $index;
                break;
            }
        }

        if ($live === null) {
            return OpPrecondition::NeedsApply;
        }

        if ((int) ($live['partial'] ?? 0) === 1) {
            throw IncompatibleSchemaStateException::index(
                $op->table,
                $name,
                'declared a full index, found a partial one',
            );
        }

        if ((int) ($live['unique'] ?? 0) !== ($op->unique ? 1 : 0)) {
            throw IncompatibleSchemaStateException::index(
                $op->table,
                $name,
                sprintf('declared %sUNIQUE, found the opposite', $op->unique ? '' : 'non-'),
            );
        }

        // index_xinfo (not index_info) exposes sort direction. An authored
        // AddIndex has no descending form, so a DESC column is a real mismatch
        // and must refuse rather than silently satisfy.
        $liveColumns = [];
        foreach ($this->connection->fetchAllAssociative(
            sprintf('PRAGMA index_xinfo(%s)', $this->quote($name)),
        ) as $column) {
            if ((int) ($column['key'] ?? 0) !== 1) {
                continue;
            }
            $columnName = (string) ($column['name'] ?? '');
            // cid < 0 marks an expression or rowid entry. The authored op models
            // plain columns only, so equivalence cannot be established.
            if ((int) ($column['cid'] ?? 0) < 0) {
                throw IncompatibleSchemaStateException::index(
                    $op->table,
                    $name,
                    'declared plain columns, found an expression or rowid entry',
                );
            }
            $expectedCollation = $this->columnCollation($op->table, $columnName);
            if ($expectedCollation === null) {
                throw IncompatibleSchemaStateException::index(
                    $op->table,
                    $name,
                    sprintf(
                        'the collation of column "%s" could not be established from the stored schema, '
                        . 'so index equivalence cannot be proven',
                        $columnName,
                    ),
                );
            }
            $actualCollation = strtoupper(trim((string) ($column['coll'] ?? '')));
            if ($actualCollation !== $expectedCollation) {
                throw IncompatibleSchemaStateException::index(
                    $op->table,
                    $name,
                    sprintf(
                        'column "%s" would index under %s (inherited from the column), found %s',
                        $columnName,
                        $expectedCollation,
                        $actualCollation === '' ? '(none)' : $actualCollation,
                    ),
                );
            }
            if ((int) ($column['desc'] ?? 0) === 1) {
                throw IncompatibleSchemaStateException::index(
                    $op->table,
                    $name,
                    sprintf('declared ascending columns, found "%s" descending', (string) ($column['name'] ?? '')),
                );
            }
            $liveColumns[] = $column['name'];
        }

        if ($liveColumns !== $op->columns) {
            throw IncompatibleSchemaStateException::index(
                $op->table,
                $name,
                sprintf(
                    'declared columns (%s), found (%s)',
                    implode(', ', $op->columns),
                    implode(', ', array_map(strval(...), $liveColumns)),
                ),
            );
        }

        return OpPrecondition::AlreadySatisfied;
    }

    /**
     * SQLite storage-class affinity for a declared column type.
     *
     * Implements the determination rules from SQLite's datatype documentation,
     * in their documented precedence order. This is what makes two spellings of
     * the same column — the compiler's `TEXT` and Doctrine's `CLOB` — compare
     * equal, while a genuinely different storage class still fails closed.
     */
    private static function affinity(string $declaredType): string
    {
        $type = strtoupper($declaredType);

        if (str_contains($type, 'INT')) {
            return 'INTEGER';
        }
        if (str_contains($type, 'CHAR') || str_contains($type, 'CLOB') || str_contains($type, 'TEXT')) {
            return 'TEXT';
        }
        if ($type === '' || str_contains($type, 'BLOB')) {
            return 'BLOB';
        }
        if (str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB')) {
            return 'REAL';
        }

        return 'NUMERIC';
    }

    /**
     * The live column's default as a SQL literal, or null when it has none.
     *
     * SQLite stores the default as the literal expression that created it, so
     * this is left verbatim apart from trimming. An unquoted `NULL` — which is
     * what Doctrine emits for a nullable column — means "no default"; a quoted
     * `'NULL'` is the four-character string and is preserved.
     */
    private static function normalizeActualDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || strtoupper($text) === 'NULL') {
            return null;
        }

        return $text;
    }

    /**
     * The collation an authored index would use for this column, or null when
     * it cannot be established.
     *
     * An authored {@see AddIndex} declares no collation, so the index the
     * compiler emits inherits the column's. SQLite exposes that only in the
     * stored DDL, which {@see SqliteTableDefinition} scans. A null result means
     * "unknown", and callers fail closed rather than assuming `BINARY` — an
     * assumption would silently accept an index with different uniqueness and
     * ordering semantics, which is the whole reason collation is checked.
     */
    private function columnCollation(string $table, string $column): ?string
    {
        return $this->definition($table)?->collationOf($column);
    }

    /** The table's stored definition, or null when it cannot be retrieved. */
    private function definition(string $table): ?SqliteTableDefinition
    {
        $sql = $this->connection->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        );

        return is_string($sql) && $sql !== '' ? new SqliteTableDefinition($sql) : null;
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        ) === 1;
    }

    /**
     * Columns as `table_xinfo` reports them.
     *
     * `table_info` omits generated columns, which made a generated target look
     * absent — outstanding rather than incompatible — and deferred the failure to
     * a raw SQL error. `table_xinfo` is the same rows plus `hidden`.
     *
     * @return list<array<string, mixed>>
     */
    private function columns(string $table): array
    {
        return $this->connection->fetchAllAssociative(
            sprintf('PRAGMA table_xinfo(%s)', $this->quote($table)),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function indexes(string $table): array
    {
        return $this->connection->fetchAllAssociative(
            sprintf('PRAGMA index_list(%s)', $this->quote($table)),
        );
    }

    /** Whether a named index includes this column among its key columns. */
    private function indexCovers(string $index, string $column): bool
    {
        foreach ($this->connection->fetchAllAssociative(
            sprintf('PRAGMA index_info(%s)', $this->quote($index)),
        ) as $entry) {
            if (strcasecmp((string) ($entry['name'] ?? ''), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
