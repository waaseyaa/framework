<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Backend\DatabaseStrictFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayAttempt;
use Waaseyaa\EntityStorage\Backend\FieldStorageGatewayOperation;

final class DatabaseStrictFieldStorageGatewayAuditTest extends TestCase
{
    #[Test]
    public function kernel_fallback_persists_value_free_reservation_and_finalization_events(): void
    {
        $database = DBALDatabase::createSqlite();
        $audit = new DatabaseStrictFieldStorageGatewayAudit($database);
        $attempt = new FieldStorageGatewayAttempt(
            backendId: 'sql-column',
            fingerprint: str_repeat('a', 64),
            operation: FieldStorageGatewayOperation::Write,
            entityTypeId: 'user',
            entityId: 12,
            fieldName: 'mail',
        );

        $receipt = $audit->reserve($attempt);
        $audit->succeed($receipt);

        $rows = iterator_to_array($database->query(
            'SELECT receipt_id, event_type, outcome, descriptor FROM privileged_read_ledger ORDER BY id',
        ));
        self::assertCount(2, $rows);
        self::assertSame($rows[0]['receipt_id'], $rows[1]['receipt_id']);
        self::assertSame('reserved', $rows[0]['event_type']);
        self::assertSame('finalized', $rows[1]['event_type']);
        self::assertSame('succeeded', $rows[1]['outcome']);
        self::assertStringContainsString('"field_name":"mail"', (string) $rows[0]['descriptor']);
        self::assertStringNotContainsString('member@example.test', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
