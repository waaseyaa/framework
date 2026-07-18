<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\EntityStorage\Validation\DatabaseValidationReadLedger;

final class DatabaseValidationReadLedgerTest extends TestCase
{
    #[Test]
    public function closed_validation_is_reserved_before_a_value_read_and_finalized_without_the_value(): void
    {
        $database = DBALDatabase::createSqlite();
        $ledger = new DatabaseValidationReadLedger($database);

        $reservation = $ledger->reserve(new EntityStructure('user', 'user', 12, null), 'mail');
        $reservation->finalize(true);

        $rows = iterator_to_array($database->query(
            'SELECT receipt_id, event_type, outcome, descriptor FROM privileged_read_ledger ORDER BY id',
        ));
        self::assertCount(2, $rows);
        self::assertSame($rows[0]['receipt_id'], $rows[1]['receipt_id']);
        self::assertSame('reserved', $rows[0]['event_type']);
        self::assertSame('finalized', $rows[1]['event_type']);
        self::assertSame('succeeded', $rows[1]['outcome']);
        self::assertStringContainsString('"kind":"entity_validation"', (string) $rows[0]['descriptor']);
        self::assertStringContainsString('"field_name":"mail"', (string) $rows[0]['descriptor']);
        self::assertStringNotContainsString('member@example.test', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
