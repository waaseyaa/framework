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
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Writer\DatabaseStrictPrivilegedReadLedger;
use Waaseyaa\Database\DBALDatabase;

final class StrictPrivilegedReadLedgerTest extends TestCase
{
    #[Test]
    public function reservation_and_finalization_are_durable_append_only_events_without_values(): void
    {
        $database = DBALDatabase::createSqlite();
        (new AuditEventSchemaHandler($database))->ensureSchema();
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
        (new AuditEventSchemaHandler($database))->ensureSchema();
        $ledger = new DatabaseStrictPrivilegedReadLedger($database);
        $transaction = $database->transaction('entity-write');

        $receipt = $ledger->reserve($this->descriptor());
        $ledger->finalize($receipt, PrivilegedReadOutcome::Succeeded);
        $transaction->rollBack();

        self::assertSame([], iterator_to_array($database->query('SELECT * FROM privileged_read_ledger')));
    }

    #[Test]
    public function unknown_or_already_finalized_receipts_are_rejected(): void
    {
        $database = DBALDatabase::createSqlite();
        (new AuditEventSchemaHandler($database))->ensureSchema();
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
        (new AuditEventSchemaHandler($database))->ensureSchema();
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
