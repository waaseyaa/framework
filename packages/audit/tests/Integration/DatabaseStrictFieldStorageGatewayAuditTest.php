<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Writer\DatabaseStrictFieldStorageGatewayAudit;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAttempt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayFailure;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOperation;

final class DatabaseStrictFieldStorageGatewayAuditTest extends TestCase
{
    #[Test]
    public function reservation_and_success_are_durable_value_free_events(): void
    {
        $database = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($database)->ensureSchema();
        $audit = new DatabaseStrictFieldStorageGatewayAudit($database);

        $receipt = $audit->reserve($this->attempt());
        $audit->succeed($receipt);

        $rows = iterator_to_array($database->query(
            'SELECT receipt_id, event_type, outcome, descriptor FROM privileged_read_ledger ORDER BY id',
        ));
        self::assertCount(2, $rows);
        self::assertSame($rows[0]['receipt_id'], $rows[1]['receipt_id']);
        self::assertSame('reserved', $rows[0]['event_type']);
        self::assertSame('finalized', $rows[1]['event_type']);
        self::assertSame('succeeded', $rows[1]['outcome']);
        self::assertStringContainsString('"kind":"field_storage_gateway"', (string) $rows[0]['descriptor']);
        self::assertStringContainsString('"field_name":"mail"', (string) $rows[0]['descriptor']);
        self::assertStringNotContainsString('member@example.test', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function failure_records_whether_backend_invocation_started(): void
    {
        $database = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($database)->ensureSchema();
        $audit = new DatabaseStrictFieldStorageGatewayAudit($database);
        $attempt = $this->attempt();

        $receipt = $audit->reserve($attempt);
        $audit->fail($receipt, new FieldStorageGatewayFailure($attempt, \RuntimeException::class, true));

        $rows = iterator_to_array($database->query(
            "SELECT outcome FROM privileged_read_ledger WHERE event_type = 'finalized'",
        ));
        self::assertSame([['outcome' => 'failed_started']], $rows);
    }

    #[Test]
    public function failure_for_a_different_attempt_cannot_finalize_the_reservation(): void
    {
        $database = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($database)->ensureSchema();
        $audit = new DatabaseStrictFieldStorageGatewayAudit($database);
        $receipt = $audit->reserve($this->attempt());
        $differentAttempt = new FieldStorageGatewayAttempt(
            backendId: 'sql-column',
            fingerprint: str_repeat('b', 64),
            operation: FieldStorageGatewayOperation::Write,
            entityTypeId: 'user',
            entityId: 12,
            fieldName: 'mail',
        );

        $this->expectException(\LogicException::class);
        $audit->fail($receipt, new FieldStorageGatewayFailure($differentAttempt, \RuntimeException::class, false));
    }

    private function attempt(): FieldStorageGatewayAttempt
    {
        return new FieldStorageGatewayAttempt(
            backendId: 'sql-column',
            fingerprint: str_repeat('a', 64),
            operation: FieldStorageGatewayOperation::Write,
            entityTypeId: 'user',
            entityId: 12,
            fieldName: 'mail',
        );
    }
}
