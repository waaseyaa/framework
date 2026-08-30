<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

use Doctrine\DBAL\Connection;
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
 * runtime, immediately before the operation would run.
 *
 * **Deliberately narrow.** Only additive operations whose satisfaction can be
 * decided *exactly* participate: {@see AddColumn} and {@see AddIndex}. Every
 * other kind reports {@see OpPrecondition::NeedsApply} and is left to the
 * compiler's policy gates and to SQLite itself, so nothing is skipped on the
 * strength of a name. Renames and drops in particular are never treated as
 * already satisfied: an absent source is ambiguous, and guessing would risk
 * silently accepting an unrelated schema.
 *
 * **Fail closed.** A column or index that exists but does not match the declared
 * shape throws {@see IncompatibleSchemaStateException} rather than applying over
 * it or skipping it.
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
        $actualType = strtoupper(trim((string) ($live['type'] ?? '')));
        if ($actualType !== strtoupper($expectedType)) {
            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                sprintf('declared type %s, found %s', $expectedType, $actualType === '' ? '(none)' : $actualType),
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

        $actualDefault = $live['dflt_value'] ?? null;
        if ($op->spec->default === null) {
            if ($actualDefault !== null) {
                throw IncompatibleSchemaStateException::column(
                    $op->table,
                    $op->column,
                    sprintf('declared no default, found DEFAULT %s', (string) $actualDefault),
                );
            }
        } elseif ($actualDefault === null) {
            throw IncompatibleSchemaStateException::column(
                $op->table,
                $op->column,
                'declared a default, found none',
            );
        }

        return OpPrecondition::AlreadySatisfied;
    }

    private function resolveAddIndex(AddIndex $op): OpPrecondition
    {
        if (!$this->tableExists($op->table)) {
            return OpPrecondition::NeedsApply;
        }

        foreach ($this->connection->fetchAllAssociative(
            sprintf('PRAGMA index_list(%s)', $this->quote($op->table)),
        ) as $index) {
            $name = (string) ($index['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $columns = array_column(
                $this->connection->fetchAllAssociative(sprintf('PRAGMA index_info(%s)', $this->quote($name))),
                'name',
            );
            if ($columns !== $op->columns) {
                continue;
            }
            if ((int) ($index['unique'] ?? 0) !== ($op->unique ? 1 : 0)) {
                throw IncompatibleSchemaStateException::index(
                    $op->table,
                    $name,
                    sprintf('declared %sUNIQUE, found the opposite', $op->unique ? '' : 'non-'),
                );
            }

            return OpPrecondition::AlreadySatisfied;
        }

        return OpPrecondition::NeedsApply;
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
