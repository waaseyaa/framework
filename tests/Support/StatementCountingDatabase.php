<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;

/**
 * A {@see DatabaseInterface} that counts the statements asked of it.
 *
 * For asserting that a code path does not grow its database work — an
 * *invariant* of the code, measurable exactly, rather than a wall-clock
 * measurement that a loaded CI runner can move underneath the assertion.
 *
 * The counter is on statement CONSTRUCTION (`select()`, `insert()`, …), which
 * is the framework's per-statement seam: every query the storage layer issues
 * begins with exactly one of these calls, or with `query()` for raw SQL.
 * Counting here rather than at the driver also means the count is a property of
 * the code under test rather than of the SQL dialect underneath it.
 *
 * `quoteIdentifier()` is deliberately NOT counted: it does not issue a
 * statement. `schema()` IS counted: save-time `tableExists` / `fieldExists`
 * go through it and reach SQLite via Doctrine. Setup DDL must stay on the
 * undecorated handle (S1-DB107) so table creation does not swamp the signal —
 * that is a callsite choice, not a reason to leave schema probes invisible.
 *
 * @api
 */
final class StatementCountingDatabase implements DatabaseInterface
{
    /** @var array<string, int> */
    private array $counts = ['select' => 0, 'insert' => 0, 'update' => 0, 'delete' => 0, 'transaction' => 0, 'query' => 0, 'schema' => 0];

    public function __construct(private readonly DatabaseInterface $inner) {}

    /** Total statements since the last {@see self::reset()}. */
    public function total(): int
    {
        return array_sum($this->counts);
    }

    /** @return array<string, int> Per-kind counts, for a failure message that says WHAT grew. */
    public function counts(): array
    {
        return $this->counts;
    }

    public function reset(): void
    {
        $this->counts = array_map(static fn(): int => 0, $this->counts);
    }

    public function select(string $table, string $alias = ''): SelectInterface
    {
        $this->counts['select']++;

        return $this->inner->select($table, $alias);
    }

    public function insert(string $table): InsertInterface
    {
        $this->counts['insert']++;

        return $this->inner->insert($table);
    }

    public function update(string $table): UpdateInterface
    {
        $this->counts['update']++;

        return $this->inner->update($table);
    }

    public function delete(string $table): DeleteInterface
    {
        $this->counts['delete']++;

        return $this->inner->delete($table);
    }

    public function transaction(string $name = ''): TransactionInterface
    {
        $this->counts['transaction']++;

        return $this->inner->transaction($name);
    }

    public function query(string $sql, array $args = []): \Traversable
    {
        $this->counts['query']++;

        return $this->inner->query($sql, $args);
    }

    public function schema(): SchemaInterface
    {
        $this->counts['schema']++;

        return $this->inner->schema();
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->inner->quoteIdentifier($identifier);
    }
}
