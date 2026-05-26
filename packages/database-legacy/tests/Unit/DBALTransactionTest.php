<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DBALTransaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DBALTransaction::class)]
final class DBALTransactionTest extends TestCase
{
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
    }

    public function testCommit(): void
    {
        $tx = $this->db->transaction();

        $tx->commit();

        // Should not throw; transaction is completed.
        $this->assertTrue(true);
    }

    public function testRollBack(): void
    {
        $tx = $this->db->transaction();

        $tx->rollBack();

        // Should not throw; transaction is completed.
        $this->assertTrue(true);
    }

    public function testDoubleCommitThrows(): void
    {
        $tx = $this->db->transaction();
        $tx->commit();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction is no longer active.');

        $tx->commit();
    }

    public function testDoubleRollBackThrows(): void
    {
        $tx = $this->db->transaction();
        $tx->rollBack();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction is no longer active.');

        $tx->rollBack();
    }

    public function testCommitAfterRollBackThrows(): void
    {
        $tx = $this->db->transaction();
        $tx->rollBack();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction is no longer active.');

        $tx->commit();
    }
}
