<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Storage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;

/**
 * Active append-only enforcement for the OCAP audit log at the database layer.
 *
 * Decorates a real {@see DatabaseInterface} and refuses any `UPDATE` or `DELETE`
 * targeting the `audit_event` table, throwing {@see \LogicException}. Inserts,
 * reads, schema operations, and all access to other tables pass through
 * untouched. This is the structural guarantee behind the append-only invariant
 * (FR-003): the {@see \Waaseyaa\Audit\Writer\AuditEventWriter} is wired with this
 * decorator, so the only mutation it can express is an append.
 *
 * The one sanctioned bulk-delete path — `audit:prune` retention purging
 * ({@see \Waaseyaa\CLI\Command\Audit\PruneCommand}) — deliberately resolves the
 * raw {@see DatabaseInterface} from the container, not this decorator, so
 * retention works while every writer path stays immutable.
 *
 * Replaces the former `AppendOnlyDriverGuard`, which guarded the entity-storage
 * driver — a path that no longer exists now that `audit_event` is a plain OCAP
 * log table rather than a registered content entity.
 *
 * @api
 */
final class AppendOnlyAuditDatabase implements DatabaseInterface
{
    /**
     * Tables on which UPDATE and DELETE are forbidden through this decorator.
     *
     * @var list<string>
     */
    private const APPEND_ONLY_TABLES = ['audit_event'];

    public function __construct(
        private readonly DatabaseInterface $inner,
    ) {}

    public function select(string $table, string $alias = ''): SelectInterface
    {
        return $this->inner->select($table, $alias);
    }

    public function insert(string $table): InsertInterface
    {
        return $this->inner->insert($table);
    }

    public function update(string $table): UpdateInterface
    {
        $this->assertMutable($table, 'UPDATE');

        return $this->inner->update($table);
    }

    public function delete(string $table): DeleteInterface
    {
        $this->assertMutable($table, 'DELETE');

        return $this->inner->delete($table);
    }

    public function schema(): SchemaInterface
    {
        return $this->inner->schema();
    }

    public function transaction(string $name = ''): TransactionInterface
    {
        return $this->inner->transaction($name);
    }

    /** @return \Traversable<int|string, mixed> */
    public function query(string $sql, array $args = []): \Traversable
    {
        return $this->inner->query($sql, $args);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->inner->quoteIdentifier($identifier);
    }

    private function assertMutable(string $table, string $operation): void
    {
        if (in_array($table, self::APPEND_ONLY_TABLES, true)) {
            throw new \LogicException(sprintf(
                'Audit table "%s" is append-only (OCAP FR-003): %s is forbidden through the audit '
                . 'database. Records may only be appended; bulk retention deletion goes through '
                . 'audit:prune via the raw DatabaseInterface.',
                $table,
                $operation,
            ));
        }
    }
}
