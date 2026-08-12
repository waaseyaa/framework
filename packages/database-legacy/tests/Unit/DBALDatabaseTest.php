<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use Waaseyaa\Database\ConsistentReadDatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\SqliteTopology;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DBALDatabase::class)]
final class DBALDatabaseTest extends TestCase
{
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
    }

    public function testCreateSqliteReturnsInstance(): void
    {
        $db = DBALDatabase::createSqlite();

        $this->assertInstanceOf(DBALDatabase::class, $db);
    }

    public function testCreateSqliteEnforcesForeignKeysOnEveryNewConnection(): void
    {
        $first = DBALDatabase::createSqlite();
        $second = DBALDatabase::createSqlite();

        foreach ([$first, $second] as $database) {
            $connection = $database->getConnection();
            $connection->executeStatement('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
            $connection->executeStatement(
                'CREATE TABLE child (parent_id INTEGER NOT NULL REFERENCES parent(id))',
            );

            try {
                $connection->executeStatement('INSERT INTO child (parent_id) VALUES (404)');
                $this->fail('A new SQLite connection accepted an orphaned foreign key.');
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFileConnectionUsesTheVerifiedS1Pragmas(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa-s1-' . bin2hex(random_bytes(8)) . '.sqlite';

        try {
            $connection = DBALDatabase::createSqlite($path)->getConnection();

            $this->assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
            $this->assertSame('wal', strtolower((string) $connection->fetchOne('PRAGMA journal_mode')));
            $this->assertSame(5000, (int) $connection->fetchOne('PRAGMA busy_timeout'));
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    public function testDsnAndNetworkSharePathsFailWithTheStableS1Diagnostic(): void
    {
        foreach (['mysql:waaseyaa', 'postgresql://db/waaseyaa', 'sqlite:/tmp/waaseyaa', '\\\\server\\share\\waaseyaa.sqlite', '//server/share/waaseyaa.sqlite'] as $path) {
            try {
                DBALDatabase::createSqlite($path);
                $this->fail("Unsupported SQLite path was accepted: {$path}");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('S1-DB001', $exception->getMessage());
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testEffectivePragmaDriftFailsWithTheStableS1Diagnostic(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa-s1-drift-' . bin2hex(random_bytes(8)) . '.sqlite';

        try {
            $connection = DBALDatabase::createSqlite($path)->getConnection();
            $connection->executeStatement('PRAGMA busy_timeout = 1');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('S1-DB003');
            SqliteTopology::assertEffectivePragmas($connection, fileBacked: true);
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    public function testSelectReturnsSelectInterface(): void
    {
        $select = $this->db->select('users', 'u');

        $this->assertInstanceOf(SelectInterface::class, $select);
    }

    public function testInsertReturnsInsertInterface(): void
    {
        $insert = $this->db->insert('users');

        $this->assertInstanceOf(InsertInterface::class, $insert);
    }

    public function testUpdateReturnsUpdateInterface(): void
    {
        $update = $this->db->update('users');

        $this->assertInstanceOf(UpdateInterface::class, $update);
    }

    public function testDeleteReturnsDeleteInterface(): void
    {
        $delete = $this->db->delete('users');

        $this->assertInstanceOf(DeleteInterface::class, $delete);
    }

    public function testSchemaReturnsSchemaInterface(): void
    {
        $schema = $this->db->schema();

        $this->assertInstanceOf(SchemaInterface::class, $schema);
    }

    public function testTransactionReturnsTransactionInterface(): void
    {
        $transaction = $this->db->transaction();

        $this->assertInstanceOf(TransactionInterface::class, $transaction);
        // Clean up: commit the transaction so SQLite doesn't complain.
        $transaction->commit();
    }

    public function testConsistentReadTransactionUsesRepeatableReadAndRestoresIsolation(): void
    {
        $connection = $this->db->getConnection();
        $before = $connection->getTransactionIsolation();
        $transaction = $this->db->consistentReadTransaction();

        $this->assertInstanceOf(ConsistentReadDatabaseInterface::class, $this->db);
        $this->assertSame(TransactionIsolationLevel::REPEATABLE_READ, $connection->getTransactionIsolation());
        $transaction->commit();
        $this->assertSame($before, $connection->getTransactionIsolation());
    }

    public function testQueryExecutesRawSql(): void
    {
        $this->db->query('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->query('INSERT INTO test (name) VALUES (?)', ['Alice']);
        $this->db->query('INSERT INTO test (name) VALUES (?)', ['Bob']);

        $result = $this->db->query('SELECT name FROM test ORDER BY name');

        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row['name'];
        }

        $this->assertSame(['Alice', 'Bob'], $rows);
    }

    public function testQueryWithParameterBinding(): void
    {
        $this->db->query('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->query('INSERT INTO test (name) VALUES (?)', ['Alice']);
        $this->db->query('INSERT INTO test (name) VALUES (?)', ['Bob']);

        $result = $this->db->query('SELECT name FROM test WHERE name = ?', ['Alice']);

        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row['name'];
        }

        $this->assertSame(['Alice'], $rows);
    }

    public function testGetConnectionReturnsUnderlyingConnection(): void
    {
        $connection = $this->db->getConnection();

        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testFullCrudWorkflow(): void
    {
        // Create table.
        $this->db->schema()->createTable('items', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'value' => ['type' => 'int', 'default' => 0],
            ],
            'primary key' => ['id'],
        ]);

        // Insert.
        $id = $this->db->insert('items')
            ->values(['name' => 'widget', 'value' => 42])
            ->execute();
        $this->assertSame('1', (string) $id);

        // Select.
        $rows = iterator_to_array(
            $this->db->select('items', 'i')
                ->fields('i', ['name', 'value'])
                ->condition('i.id', 1)
                ->execute(),
        );
        $this->assertCount(1, $rows);
        $this->assertSame('widget', $rows[0]['name']);

        // Update.
        $affected = $this->db->update('items')
            ->fields(['value' => 100])
            ->condition('id', 1)
            ->execute();
        $this->assertSame(1, $affected);

        // Verify update.
        $rows = iterator_to_array(
            $this->db->select('items', 'i')
                ->fields('i', ['value'])
                ->condition('i.id', 1)
                ->execute(),
        );
        $this->assertSame(100, (int) $rows[0]['value']);

        // Delete.
        $affected = $this->db->delete('items')
            ->condition('id', 1)
            ->execute();
        $this->assertSame(1, $affected);

        // Verify delete.
        $rows = iterator_to_array(
            $this->db->select('items', 'i')
                ->fields('i')
                ->execute(),
        );
        $this->assertCount(0, $rows);
    }

    public function testTransactionCommit(): void
    {
        $this->db->schema()->createTable('txtest', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            ],
            'primary key' => ['id'],
        ]);

        $tx = $this->db->transaction();
        $this->db->insert('txtest')->values(['name' => 'committed'])->execute();
        $tx->commit();

        $rows = iterator_to_array(
            $this->db->select('txtest', 't')->fields('t')->execute(),
        );
        $this->assertCount(1, $rows);
        $this->assertSame('committed', $rows[0]['name']);
    }

    public function testTransactionRollback(): void
    {
        $this->db->schema()->createTable('txtest', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            ],
            'primary key' => ['id'],
        ]);

        $tx = $this->db->transaction();
        $this->db->insert('txtest')->values(['name' => 'rolled_back'])->execute();
        $tx->rollBack();

        $rows = iterator_to_array(
            $this->db->select('txtest', 't')->fields('t')->execute(),
        );
        $this->assertCount(0, $rows);
    }
}
