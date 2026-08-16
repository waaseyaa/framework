<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadKind;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Writer\DatabaseStrictPrivilegedReadLedger;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

final class StrictPrivilegedReadLedgerTest extends TestCase
{
    #[Test]
    public function reservation_and_finalization_are_durable_append_only_events_without_values(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $ledger = new DatabaseStrictPrivilegedReadLedger($database);

        $receipt = $ledger->reserve($this->descriptor());
        $ledger->finalize($receipt, PrivilegedReadOutcome::Succeeded);

        $rows = iterator_to_array($database->query(
            'SELECT event_type, outcome, descriptor FROM privileged_read_ledger ORDER BY id',
        ));
        self::assertCount(2, $rows);
        self::assertSame('reserved', $rows[0]['event_type']);
        self::assertNull($rows[0]['outcome']);
        self::assertSame('finalized', $rows[1]['event_type']);
        self::assertSame('succeeded', $rows[1]['outcome']);
        self::assertStringNotContainsString('member@example.test', (string) $rows[0]['descriptor']);
        self::assertStringContainsString('"fields":["mail","roles"]', (string) $rows[0]['descriptor']);
    }

    #[Test]
    public function reservation_and_success_share_the_callers_database_transaction(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $ledger = new DatabaseStrictPrivilegedReadLedger($database);
        $transaction = $database->transaction('entity-write');

        $receipt = $ledger->reserve($this->descriptor());
        $ledger->finalize($receipt, PrivilegedReadOutcome::Succeeded);
        $transaction->rollBack();

        self::assertSame([], iterator_to_array($database->query('SELECT * FROM privileged_read_ledger')));
    }

    #[Test]
    public function batch_reservation_and_finalization_preserve_entity_scoped_evidence(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $ledger = new DatabaseStrictPrivilegedReadLedger($database);
        $first = $this->descriptor();
        $second = new PrivilegedReadDescriptor(
            kind: $first->kind,
            reason: $first->reason,
            issuer: $first->issuer,
            actorSemantics: $first->actorSemantics,
            actorId: $first->actorId,
            entityTypeId: $first->entityTypeId,
            entityId: 13,
            fields: $first->fields,
            bundles: $first->bundles,
            tenantId: $first->tenantId,
            communityId: $first->communityId,
            queryFingerprint: null,
            queryOperations: [],
            classificationGeneration: $first->classificationGeneration,
            policyGeneration: $first->policyGeneration,
            correlationId: $first->correlationId,
            callSite: $first->callSite,
        );

        $receipts = $ledger->reserveMany([$first, $second]);
        $ledger->finalizeMany($receipts, PrivilegedReadOutcome::Succeeded);

        $rows = iterator_to_array($database->query(
            'SELECT receipt_id, event_type, outcome, descriptor FROM privileged_read_ledger ORDER BY id',
        ));
        self::assertCount(4, $rows);
        self::assertSame(['reserved', 'reserved', 'finalized', 'finalized'], array_column($rows, 'event_type'));
        self::assertSame([$receipts[0]->id, $receipts[1]->id, $receipts[0]->id, $receipts[1]->id], array_column($rows, 'receipt_id'));
        self::assertStringContainsString('"entity_id":12', (string) $rows[0]['descriptor']);
        self::assertStringContainsString('"entity_id":13', (string) $rows[1]['descriptor']);
        self::assertSame(['succeeded', 'succeeded'], array_column(array_slice($rows, 2), 'outcome'));
    }

    #[Test]
    public function empty_reservation_batches_are_rejected_before_opening_a_transaction(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $ledger = new DatabaseStrictPrivilegedReadLedger($database);

        $this->expectException(\InvalidArgumentException::class);
        $ledger->reserveMany([]);
    }

    #[Test]
    public function empty_finalization_batches_are_rejected_before_opening_a_transaction(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $ledger = new DatabaseStrictPrivilegedReadLedger($database);

        $this->expectException(\InvalidArgumentException::class);
        $ledger->finalizeMany([], PrivilegedReadOutcome::Succeeded);
    }

    #[Test]
    public function duplicate_receipts_roll_back_the_whole_finalization_batch(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $ledger = new DatabaseStrictPrivilegedReadLedger($database);
        $receipt = $ledger->reserve($this->descriptor());

        try {
            $ledger->finalizeMany([$receipt, $receipt], PrivilegedReadOutcome::Succeeded);
            self::fail('Duplicate receipts must fail the finalization batch.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Finalization batches require unique privileged-read receipts.', $exception->getMessage());
        }

        self::assertSame(
            [['event_type' => 'reserved']],
            iterator_to_array($database->query(
                'SELECT event_type FROM privileged_read_ledger WHERE receipt_id = :receipt ORDER BY id',
                ['receipt' => $receipt->id],
            )),
        );
    }

    #[Test]
    public function unknown_or_already_finalized_receipts_are_rejected(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $ledger = new DatabaseStrictPrivilegedReadLedger($database);
        $receipt = $ledger->reserve($this->descriptor());
        $ledger->finalize($receipt, PrivilegedReadOutcome::Failed);

        $this->expectException(\LogicException::class);
        $ledger->finalize($receipt, PrivilegedReadOutcome::Succeeded);
    }

    #[Test]
    public function interrupted_reservation_remains_visible_and_schema_forbids_conflicting_finalization_events(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $ledger = new DatabaseStrictPrivilegedReadLedger($database);
        $receipt = $ledger->reserve($this->descriptor());

        $rows = iterator_to_array($database->query('SELECT event_type FROM privileged_read_ledger WHERE receipt_id = :receipt', ['receipt' => $receipt->id]));
        self::assertSame([['event_type' => 'reserved']], $rows);

        $database->insert('privileged_read_ledger')->values([
            'receipt_id' => $receipt->id,
            'event_type' => 'finalized',
            'outcome' => 'failed',
            'descriptor' => null,
            'created_at' => '2030-01-01 00:00:00',
        ])->execute();
        $this->expectException(\Throwable::class);
        $database->insert('privileged_read_ledger')->values([
            'receipt_id' => $receipt->id,
            'event_type' => 'finalized',
            'outcome' => 'succeeded',
            'descriptor' => null,
            'created_at' => '2030-01-01 00:00:01',
        ])->execute();
    }

    #[Test]
    public function finalization_retries_after_a_concurrent_wal_snapshot_is_invalidated(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'waaseyaa-ledger-contention-');
        self::assertIsString($path);
        try {
            $first = DBALDatabase::createSqlite($path);
            RuntimeSchemaMigrations::audit($first);
            $second = DBALDatabase::createSqlite($path);
            $secondLedger = new DatabaseStrictPrivilegedReadLedger($second);
            $secondReceipt = $secondLedger->reserve($this->descriptor());
            $triggered = false;
            $proxy = new class ($first, function () use (&$triggered, $secondLedger, $secondReceipt): void {
                if (!$triggered) {
                    $triggered = true;
                    $secondLedger->finalize($secondReceipt, PrivilegedReadOutcome::Succeeded);
                }
            }) implements DatabaseInterface {
                public function __construct(private readonly DatabaseInterface $inner, private readonly \Closure $afterFirstRead) {}
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
                    return $this->inner->update($table);
                }
                public function delete(string $table): DeleteInterface
                {
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
                public function quoteIdentifier(string $identifier): string
                {
                    return $this->inner->quoteIdentifier($identifier);
                }
                public function query(string $sql, array $args = []): \Traversable
                {
                    $rows = iterator_to_array($this->inner->query($sql, $args));
                    ($this->afterFirstRead)();
                    return new \ArrayIterator($rows);
                }
            };
            $firstLedger = new DatabaseStrictPrivilegedReadLedger($proxy);
            $firstReceipt = $firstLedger->reserve($this->descriptor());
            $firstLedger->finalize($firstReceipt, PrivilegedReadOutcome::Succeeded);

            self::assertTrue($triggered);
            self::assertSame(2, (int) iterator_to_array($first->query('SELECT COUNT(*) AS count FROM privileged_read_ledger WHERE event_type = :event', ['event' => 'finalized']))[0]['count']);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    #[Test]
    public function reservation_retries_bare_sqlite_busy_snapshot_at_transaction_acquisition(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $proxy = new class ($database) implements DatabaseInterface {
            public int $transactionAttempts = 0;
            public function __construct(private readonly DatabaseInterface $inner) {}
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
                return $this->inner->update($table);
            }
            public function delete(string $table): DeleteInterface
            {
                return $this->inner->delete($table);
            }
            public function schema(): SchemaInterface
            {
                return $this->inner->schema();
            }
            public function quoteIdentifier(string $identifier): string
            {
                return $this->inner->quoteIdentifier($identifier);
            }
            public function query(string $sql, array $args = []): \Traversable
            {
                return $this->inner->query($sql, $args);
            }
            public function transaction(string $name = ''): TransactionInterface
            {
                if (++$this->transactionAttempts === 1) {
                    throw new \RuntimeException('SQLITE_BUSY_SNAPSHOT');
                }
                return $this->inner->transaction($name);
            }
        };
        $ledger = new DatabaseStrictPrivilegedReadLedger($proxy);

        $receipts = $ledger->reserveMany([$this->descriptor()]);

        self::assertCount(1, $receipts);
        self::assertSame(2, $proxy->transactionAttempts);
        self::assertSame([['event_type' => 'reserved']], iterator_to_array($database->query('SELECT event_type FROM privileged_read_ledger')));
    }

    #[Test]
    public function ambiguous_commit_is_not_retried_when_rollback_cannot_prove_safety(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::audit($database);
        $proxy = new class ($database) implements DatabaseInterface {
            public int $transactionAttempts = 0;
            public function __construct(private readonly DatabaseInterface $inner) {}
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
                return $this->inner->update($table);
            }
            public function delete(string $table): DeleteInterface
            {
                return $this->inner->delete($table);
            }
            public function schema(): SchemaInterface
            {
                return $this->inner->schema();
            }
            public function quoteIdentifier(string $identifier): string
            {
                return $this->inner->quoteIdentifier($identifier);
            }
            public function query(string $sql, array $args = []): \Traversable
            {
                return $this->inner->query($sql, $args);
            }
            public function transaction(string $name = ''): TransactionInterface
            {
                ++$this->transactionAttempts;
                $inner = $this->inner->transaction($name);
                return new class ($inner) implements TransactionInterface {
                    public function __construct(private readonly TransactionInterface $inner) {}
                    public function commit(): void
                    {
                        $this->inner->commit();
                        throw new \RuntimeException('SQLITE_BUSY after an ambiguous commit boundary');
                    }
                    public function rollBack(): void
                    {
                        $this->inner->rollBack();
                    }
                };
            }
        };
        $ledger = new DatabaseStrictPrivilegedReadLedger($proxy);

        try {
            $ledger->reserveMany([$this->descriptor()]);
            self::fail('An ambiguous commit must fail closed.');
        } catch (\Waaseyaa\Audit\Exception\PrivilegedReadLedgerException $exception) {
            self::assertStringContainsString('could not be made durable', $exception->getMessage());
        }

        self::assertSame(1, $proxy->transactionAttempts);
        self::assertSame(1, (int) iterator_to_array($database->query('SELECT COUNT(*) AS count FROM privileged_read_ledger'))[0]['count']);
    }

    private function descriptor(): PrivilegedReadDescriptor
    {
        return new PrivilegedReadDescriptor(
            kind: PrivilegedReadKind::Value,
            reason: CapabilityReason::SessionBootstrap,
            issuer: 'http.identity-bootstrap',
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            entityTypeId: 'user',
            entityId: 12,
            fields: ['mail', 'roles'],
            bundles: ['user'],
            tenantId: 'tenant-a',
            communityId: 'community-a',
            queryFingerprint: null,
            queryOperations: [],
            classificationGeneration: 'class-1',
            policyGeneration: 'policy-1',
            correlationId: 'request-1',
            callSite: self::class,
        );
    }
}
