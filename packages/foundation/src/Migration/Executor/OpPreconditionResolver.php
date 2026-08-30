<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

use Doctrine\DBAL\Connection;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\Translator\AddIndexTranslator;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\Translator\SqliteColumnType;
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
 * @see docs/change-records/FW-2701.md — C3 already satisfied, C4 fail closed
 */
final readonly class OpPreconditionResolver
{
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

        $expectedType = SqliteColumnType::render($op->spec);
        $expectedAffinity = self::affinity($expectedType);
        $actualDeclared = trim((string) ($live['type'] ?? ''));
        $actualAffinity = self::affinity($actualDeclared);
        if ($actualAffinity !== $expectedAffinity) {
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

        $actualDefault = self::normalizeDefault($live['dflt_value'] ?? null);
        $expectedDefault = $op->spec->default === null
            ? null
            : self::normalizeDefault(self::renderDefault($op->spec->default));

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
        foreach ($this->connection->fetchAllAssociative(
            sprintf('PRAGMA index_list(%s)', $this->quote($op->table)),
        ) as $index) {
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

        $liveColumns = array_column(
            $this->connection->fetchAllAssociative(sprintf('PRAGMA index_info(%s)', $this->quote($name))),
            'name',
        );
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

    /** SQLite reports an absent default and a literal NULL default identically in effect. */
    private static function normalizeDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || strtoupper($text) === 'NULL') {
            return null;
        }
        // SQLite echoes string defaults with their quotes; compare unquoted.
        if (strlen($text) >= 2 && $text[0] === "'" && str_ends_with($text, "'")) {
            return str_replace("''", "'", substr($text, 1, -1));
        }

        return $text;
    }

    private static function renderDefault(mixed $default): string
    {
        return match (true) {
            is_bool($default) => $default ? '1' : '0',
            default => (string) $default,
        };
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        ) === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columns(string $table): array
    {
        return $this->connection->fetchAllAssociative(
            sprintf('PRAGMA table_info(%s)', $this->quote($table)),
        );
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
