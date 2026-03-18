<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Schema;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\DBALSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DBALSchema::class)]
final class DBALSchemaTest extends TestCase
{
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
    }

    public function testCreateTable(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'value' => ['type' => 'int', 'default' => 0],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('test'));
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
        $this->expectExceptionMessage('already exists');

        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
            ],
            'primary key' => ['id'],
        ]);
    }

    public function testTableExistsReturnsFalseForNonExistent(): void
    {
        $this->assertFalse($this->db->schema()->tableExists('nonexistent'));
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

    public function testFieldExistsReturnsFalseForNonExistentTable(): void
    {
        $this->assertFalse($this->db->schema()->fieldExists('nonexistent', 'id'));
    }

    public function testDropTable(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('test'));

        $this->db->schema()->dropTable('test');

        $this->assertFalse($this->db->schema()->tableExists('test'));
    }

    public function testDropTableNonExistentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->db->schema()->dropTable('nonexistent');
    }

    public function testAddField(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
            ],
            'primary key' => ['id'],
        ]);

        $this->db->schema()->addField('test', 'name', [
            'type' => 'varchar',
            'length' => 255,
        ]);

        $this->assertTrue($this->db->schema()->fieldExists('test', 'name'));
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
        $this->expectExceptionMessage('already exists');

        $this->db->schema()->addField('test', 'name', [
            'type' => 'varchar',
            'length' => 255,
        ]);
    }

    public function testAddFieldToNonExistentTableThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->db->schema()->addField('nonexistent', 'name', [
            'type' => 'varchar',
            'length' => 255,
        ]);
    }

    public function testCreateTableWithUniqueKeys(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'email' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            ],
            'primary key' => ['id'],
            'unique keys' => [
                'unique_email' => ['email'],
            ],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('test'));

        // Insert one row, then try to insert a duplicate email.
        $this->db->insert('test')->values(['email' => 'test@example.com'])->execute();

        $this->expectException(\Exception::class);
        $this->db->insert('test')->values(['email' => 'test@example.com'])->execute();
    }

    public function testCreateTableWithIndexes(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255],
                'status' => ['type' => 'int', 'default' => 1],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_status' => ['status'],
            ],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('test'));
    }

    public function testCreateTableWithCompositePrimaryKey(): void
    {
        $this->db->schema()->createTable('test', [
            'fields' => [
                'user_id' => ['type' => 'int', 'not null' => true],
                'role_id' => ['type' => 'int', 'not null' => true],
            ],
            'primary key' => ['user_id', 'role_id'],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('test'));

        // Insert valid row.
        $this->db->insert('test')->values(['user_id' => 1, 'role_id' => 1])->execute();

        // Duplicate composite key should fail.
        $this->expectException(\Exception::class);
        $this->db->insert('test')->values(['user_id' => 1, 'role_id' => 1])->execute();
    }

    public function testAddPrimaryKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SQLite does not support');

        $this->db->schema()->addPrimaryKey('test', ['id']);
    }

    public function testCreateTableWithAllFieldTypes(): void
    {
        $this->db->schema()->createTable('all_types', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'int_col' => ['type' => 'int'],
                'integer_col' => ['type' => 'integer'],
                'varchar_col' => ['type' => 'varchar', 'length' => 100],
                'text_col' => ['type' => 'text'],
                'float_col' => ['type' => 'float'],
                'blob_col' => ['type' => 'blob'],
                'bool_col' => ['type' => 'boolean'],
            ],
            'primary key' => ['id'],
        ]);

        $this->assertTrue($this->db->schema()->tableExists('all_types'));
    }
}
