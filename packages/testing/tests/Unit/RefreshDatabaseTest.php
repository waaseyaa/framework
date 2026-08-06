<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use Waaseyaa\Testing\Traits\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversTrait(RefreshDatabase::class)]
final class RefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        $this->rollBackDatabaseTransaction();
        $this->pdo = null;

        parent::tearDown();
    }

    protected function getDatabasePdo(): ?\PDO
    {
        return $this->pdo;
    }

    #[Test]
    public function beginTransactionStartsTransaction(): void
    {
        $this->assertFalse($this->pdo->inTransaction());

        $this->beginDatabaseTransaction();

        $this->assertTrue($this->pdo->inTransaction());
    }

    #[Test]
    public function rollBackRevertsChanges(): void
    {
        $this->createTestTable();

        $this->beginDatabaseTransaction();

        $stmt = $this->pdo->prepare("INSERT INTO test_items (name) VALUES ('item1')");
        $stmt->execute();

        // Data should be visible within the transaction.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM test_items')->fetchColumn();
        $this->assertSame(1, $count);

        $this->rollBackDatabaseTransaction();

        // Data should be gone after rollback.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM test_items')->fetchColumn();
        $this->assertSame(0, $count);
    }

    #[Test]
    public function rollBackIsNoOpWhenNoTransaction(): void
    {
        // Should not throw even when no transaction is active.
        $this->rollBackDatabaseTransaction();

        $this->assertTrue(true);
    }

    #[Test]
    public function migrateRunsCallback(): void
    {
        $this->migrate(function (\PDO $pdo): void {
            $stmt = $pdo->prepare('CREATE TABLE migrated_table (id INTEGER PRIMARY KEY)');
            $stmt->execute();
        });

        // Table should exist.
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrated_table'");
        $result = $stmt->fetchColumn();

        $this->assertSame('migrated_table', $result);
    }

    #[Test]
    public function truncateTablesRemovesAllRows(): void
    {
        $this->createTestTable();

        $stmt = $this->pdo->prepare("INSERT INTO test_items (name) VALUES ('a')");
        $stmt->execute();
        $stmt = $this->pdo->prepare("INSERT INTO test_items (name) VALUES ('b')");
        $stmt->execute();
        $stmt = $this->pdo->prepare("INSERT INTO test_items (name) VALUES ('c')");
        $stmt->execute();

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM test_items')->fetchColumn();
        $this->assertSame(3, $count);

        $this->truncateTables(['test_items']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM test_items')->fetchColumn();
        $this->assertSame(0, $count);
    }

    private function createTestTable(): void
    {
        $stmt = $this->pdo->prepare('CREATE TABLE test_items (id INTEGER PRIMARY KEY, name TEXT)');
        $stmt->execute();
    }
}
