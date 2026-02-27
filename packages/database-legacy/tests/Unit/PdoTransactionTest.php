<?php

declare(strict_types=1);

namespace Aurora\Database\Tests\Unit;

use Aurora\Database\PdoDatabase;
use Aurora\Database\PdoTransaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoTransaction::class)]
final class PdoTransactionTest extends TestCase
{
    private PdoDatabase $db;

    protected function setUp(): void
    {
        $this->db = PdoDatabase::createSqlite();
        $this->db->query('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function testCommitPersistsChanges(): void
    {
        $tx = $this->db->transaction();
        $this->db->query('INSERT INTO test (name) VALUES (?)', ['Alice']);
        $tx->commit();

        $result = $this->db->query('SELECT name FROM test');
        $rows = iterator_to_array($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testRollbackRevertsChanges(): void
    {
        $this->db->query('INSERT INTO test (name) VALUES (?)', ['Before']);

        $tx = $this->db->transaction();
        $this->db->query('INSERT INTO test (name) VALUES (?)', ['During']);
        $tx->rollBack();

        $result = $this->db->query('SELECT name FROM test');
        $rows = iterator_to_array($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Before', $rows[0]['name']);
    }

    public function testDoubleCommitThrowsException(): void
    {
        $tx = $this->db->transaction();
        $tx->commit();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction is no longer active.');

        $tx->commit();
    }

    public function testDoubleRollbackThrowsException(): void
    {
        $tx = $this->db->transaction();
        $tx->rollBack();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction is no longer active.');

        $tx->rollBack();
    }

    public function testCommitAfterRollbackThrowsException(): void
    {
        $tx = $this->db->transaction();
        $tx->rollBack();

        $this->expectException(\RuntimeException::class);

        $tx->commit();
    }

    public function testRollbackAfterCommitThrowsException(): void
    {
        $tx = $this->db->transaction();
        $tx->commit();

        $this->expectException(\RuntimeException::class);

        $tx->rollBack();
    }
}
