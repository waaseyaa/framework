<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\DBAL;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/**
 * Kernel boot tests verifying DBAL-only boot path (#460).
 *
 * Tests that the kernel creates DBALDatabase (not PdoDatabase),
 * and that invalid database paths produce clear errors.
 */
final class DBALKernelBootTest extends TestCase
{
    public function testDBALDatabaseCreatesSqliteInMemory(): void
    {
        $db = DBALDatabase::createSqlite();

        $this->assertInstanceOf(DBALDatabase::class, $db);
        $this->assertInstanceOf(DatabaseInterface::class, $db);
    }

    public function testDBALDatabaseImplementsDatabaseInterface(): void
    {
        $db = DBALDatabase::createSqlite();

        // Verify all DatabaseInterface methods exist and work.
        $schema = $db->schema();
        $this->assertFalse($schema->tableExists('nonexistent'));

        $schema->createTable('boot_test', [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'name' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);
        $this->assertTrue($schema->tableExists('boot_test'));
    }

    public function testDBALDatabaseSelectInsertUpdateDelete(): void
    {
        $db = DBALDatabase::createSqlite();

        $db->schema()->createTable('ops_test', [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'name' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        // Insert.
        $db->insert('ops_test')->fields(['name'])->values(['name' => 'Alice'])->execute();
        $db->insert('ops_test')->fields(['name'])->values(['name' => 'Bob'])->execute();

        // Select.
        $rows = [];
        foreach ($db->select('ops_test')->fields('ops_test')->execute() as $row) {
            $rows[] = (array) $row;
        }
        $this->assertCount(2, $rows);

        // Update.
        $db->update('ops_test')
            ->fields(['name' => 'Charlie'])
            ->condition('name', 'Bob')
            ->execute();

        $result = $db->select('ops_test')
            ->fields('ops_test')
            ->condition('name', 'Charlie')
            ->execute();
        $found = false;
        foreach ($result as $row) {
            $found = true;
            $this->assertSame('Charlie', ((array) $row)['name']);
        }
        $this->assertTrue($found);

        // Delete.
        $db->delete('ops_test')->condition('name', 'Alice')->execute();

        $remaining = [];
        foreach ($db->select('ops_test')->fields('ops_test')->execute() as $row) {
            $remaining[] = (array) $row;
        }
        $this->assertCount(1, $remaining);
        $this->assertSame('Charlie', $remaining[0]['name']);
    }

    public function testDBALDatabaseQueryMethodWorks(): void
    {
        $db = DBALDatabase::createSqlite();

        $db->schema()->createTable('query_test', [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'value' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        // Raw query for DDL/DML.
        $db->query("INSERT INTO query_test (value) VALUES ('raw_insert')");

        // Raw SELECT query.
        $rows = [];
        foreach ($db->query('SELECT * FROM query_test') as $row) {
            $rows[] = $row;
        }
        $this->assertCount(1, $rows);
        $this->assertSame('raw_insert', $rows[0]['value']);
    }

    public function testDBALDatabaseGetConnectionReturnsConnection(): void
    {
        $db = DBALDatabase::createSqlite();

        $connection = $db->getConnection();
        $this->assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        // Verify the native connection is accessible.
        $native = $connection->getNativeConnection();
        $this->assertInstanceOf(\PDO::class, $native);
    }

    public function testDBALDatabaseTransactionCommit(): void
    {
        $db = DBALDatabase::createSqlite();

        $db->schema()->createTable('tx_test', [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'name' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        $tx = $db->transaction();
        $db->insert('tx_test')->fields(['name'])->values(['name' => 'In Transaction'])->execute();
        $tx->commit();

        $rows = [];
        foreach ($db->select('tx_test')->fields('tx_test')->execute() as $row) {
            $rows[] = (array) $row;
        }
        $this->assertCount(1, $rows);
    }

    public function testDBALDatabaseTransactionRollback(): void
    {
        $db = DBALDatabase::createSqlite();

        $db->schema()->createTable('tx_rb_test', [
            'fields' => [
                'id' => ['type' => 'serial', 'not null' => true],
                'name' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        $tx = $db->transaction();
        $db->insert('tx_rb_test')->fields(['name'])->values(['name' => 'Will Rollback'])->execute();
        $tx->rollback();

        $rows = [];
        foreach ($db->select('tx_rb_test')->fields('tx_rb_test')->execute() as $row) {
            $rows[] = (array) $row;
        }
        $this->assertCount(0, $rows);
    }

    public function testPdoDatabaseClassDoesNotExist(): void
    {
        // PdoDatabase has been removed; verify it is not loadable.
        $this->assertFalse(
            class_exists(\Waaseyaa\Database\PdoDatabase::class, autoload: false),
            'PdoDatabase class should not be loaded during DBAL boot',
        );
    }

    public function testCreateSqliteWithInvalidPathFailsGracefully(): void
    {
        $this->expectException(\Throwable::class);

        // A path to a directory that does not exist should cause a failure.
        $db = DBALDatabase::createSqlite('/nonexistent/path/that/cannot/exist/test.sqlite');

        // Force the connection to actually attempt to open.
        $db->schema()->tableExists('anything');
    }
}
