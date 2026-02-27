<?php

declare(strict_types=1);

namespace Aurora\Database\Tests\Unit\Schema;

use Aurora\Database\PdoDatabase;
use Aurora\Database\Schema\PdoSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoSchema::class)]
final class PdoSchemaTest extends TestCase
{
    private PdoDatabase $db;

    protected function setUp(): void
    {
        $this->db = PdoDatabase::createSqlite();
    }

    public function testCreateTableAndTableExists(): void
    {
        $this->assertFalse($this->db->schema()->tableExists('test'));

        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => ''],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('test'));
    }

    public function testCreateTableWithAllTypes(): void
    {
        $this->db->schema()->createTable('all_types', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => ''],
                'status' => ['type' => 'int', 'size' => 'tiny', 'not null' => true, 'default' => 1],
                'body' => ['type' => 'text'],
                'weight' => ['type' => 'float'],
                'data' => ['type' => 'blob'],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('all_types'));

        // Verify we can insert and query data.
        $this->db->insert('all_types')
            ->fields(['name', 'status', 'body', 'weight'])
            ->values(['Test', 1, 'Hello world', 3.14])
            ->execute();

        $result = $this->db->query('SELECT * FROM all_types WHERE id = 1');
        $row = iterator_to_array($result)[0];

        $this->assertSame('Test', $row['name']);
        $this->assertSame(1, (int) $row['status']);
        $this->assertSame('Hello world', $row['body']);
        $this->assertEqualsWithDelta(3.14, (float) $row['weight'], 0.001);
    }

    public function testCreateTableWithIndexes(): void
    {
        $this->db->schema()->createTable('indexed', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'email' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'name_idx' => ['name'],
            ],
            'unique keys' => [
                'email_unique' => ['email'],
            ],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('indexed'));

        // Verify unique constraint works.
        $this->db->insert('indexed')
            ->fields(['name', 'email'])
            ->values(['Alice', 'alice@example.com'])
            ->execute();

        $this->expectException(\PDOException::class);

        $this->db->insert('indexed')
            ->fields(['name', 'email'])
            ->values(['Bob', 'alice@example.com'])
            ->execute();
    }

    public function testCreateTableWithCompositePrimaryKey(): void
    {
        $this->db->schema()->createTable('composite_pk', [
            'fields' => [
                'user_id' => ['type' => 'int', 'not null' => true],
                'role_id' => ['type' => 'int', 'not null' => true],
            ],
            'primary key' => ['user_id', 'role_id'],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('composite_pk'));

        $this->db->insert('composite_pk')
            ->fields(['user_id', 'role_id'])
            ->values([1, 1])
            ->execute();

        // Inserting duplicate composite key should fail.
        $this->expectException(\PDOException::class);

        $this->db->insert('composite_pk')
            ->fields(['user_id', 'role_id'])
            ->values([1, 1])
            ->execute();
    }

    public function testCreateTableAlreadyExistsThrows(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
            ],
            'primary key' => ['id'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Table "test" already exists.');

        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
            ],
            'primary key' => ['id'],
        ]);
    }

    public function testDropTable(): void
    {
        $this->db->schema()->createTable('to_drop', [
            'fields' => [
                'id' => ['type' => 'serial'],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('to_drop'));

        $this->db->schema()->dropTable('to_drop');

        $this->assertFalse($this->db->schema()->tableExists('to_drop'));
    }

    public function testDropTableNonExistentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Table "nonexistent" does not exist.');

        $this->db->schema()->dropTable('nonexistent');
    }

    public function testFieldExists(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertTrue($this->db->schema()->fieldExists('test', 'id'));
        $this->assertTrue($this->db->schema()->fieldExists('test', 'name'));
        $this->assertFalse($this->db->schema()->fieldExists('test', 'nonexistent'));
    }

    public function testFieldExistsOnNonExistentTable(): void
    {
        $this->assertFalse($this->db->schema()->fieldExists('nonexistent', 'id'));
    }

    public function testAddField(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertFalse($this->db->schema()->fieldExists('test', 'email'));

        $this->db->schema()->addField('test', 'email', [
            'type' => 'varchar',
            'length' => 255,
        ]);

        $this->assertTrue($this->db->schema()->fieldExists('test', 'email'));
    }

    public function testAddFieldToNonExistentTableThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Table "nonexistent" does not exist.');

        $this->db->schema()->addField('nonexistent', 'email', ['type' => 'varchar']);
    }

    public function testAddFieldAlreadyExistsThrows(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Field "name" already exists');

        $this->db->schema()->addField('test', 'name', ['type' => 'varchar']);
    }

    public function testDropField(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255],
                'email' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertTrue($this->db->schema()->fieldExists('test', 'email'));

        $this->db->schema()->dropField('test', 'email');

        $this->assertFalse($this->db->schema()->fieldExists('test', 'email'));
    }

    public function testDropFieldNonExistentTableThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->db->schema()->dropField('nonexistent', 'name');
    }

    public function testDropFieldNonExistentFieldThrows(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
            ],
            'primary key' => ['id'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Field "missing" does not exist');

        $this->db->schema()->dropField('test', 'missing');
    }

    public function testAddIndex(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        // Should not throw.
        $this->db->schema()->addIndex('test', 'test_name_idx', ['name']);

        // Verify the index exists by checking sqlite_master.
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM sqlite_master WHERE type = 'index' AND name = ?",
            ['test_name_idx']
        );
        $count = (int) iterator_to_array($result)[0]['cnt'];

        $this->assertSame(1, $count);
    }

    public function testDropIndex(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        $this->db->schema()->addIndex('test', 'test_name_idx', ['name']);
        $this->db->schema()->dropIndex('test', 'test_name_idx');

        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM sqlite_master WHERE type = 'index' AND name = ?",
            ['test_name_idx']
        );
        $count = (int) iterator_to_array($result)[0]['cnt'];

        $this->assertSame(0, $count);
    }

    public function testAddUniqueKey(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'email' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);

        $this->db->schema()->addUniqueKey('test', 'test_email_unique', ['email']);

        // First insert should succeed.
        $this->db->insert('test')
            ->fields(['email'])
            ->values(['test@example.com'])
            ->execute();

        // Duplicate should fail.
        $this->expectException(\PDOException::class);

        $this->db->insert('test')
            ->fields(['email'])
            ->values(['test@example.com'])
            ->execute();
    }

    public function testAddPrimaryKeyThrowsForSqlite(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'int', 'not null' => true],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SQLite does not support adding a primary key');

        $this->db->schema()->addPrimaryKey('test', ['id']);
    }

    public function testCreateTableWithDefaultValues(): void
    {
        $this->db->schema()->createTable('defaults', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => 'unnamed'],
                'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
                'weight' => ['type' => 'float', 'default' => 0.0],
            ],
            'primary key' => ['id'],
        ]);

        // Insert with only the serial field, relying on defaults.
        $this->db->query('INSERT INTO defaults DEFAULT VALUES');

        $result = $this->db->query('SELECT * FROM defaults WHERE id = 1');
        $row = iterator_to_array($result)[0];

        $this->assertSame('unnamed', $row['name']);
        $this->assertSame(1, (int) $row['status']);
        $this->assertEqualsWithDelta(0.0, (float) $row['weight'], 0.001);
    }
}
