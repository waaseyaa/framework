<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Query;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Query\DBALUpdate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DBALUpdate::class)]
final class DBALUpdateTest extends TestCase
{
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();

        $this->db->schema()->createTable('users', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'email' => ['type' => 'varchar', 'length' => 255],
                'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
            ],
            'primary key' => ['id'],
        ]);

        $this->db->insert('users')->values(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 1])->execute();
        $this->db->insert('users')->values(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 1])->execute();
        $this->db->insert('users')->values(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 0])->execute();
    }

    public function testUpdateWithSimpleCondition(): void
    {
        $affected = $this->db->update('users')
            ->fields(['name' => 'Alice Updated'])
            ->condition('id', 1)
            ->execute();

        $this->assertSame(1, $affected);

        $rows = iterator_to_array($this->db->query('SELECT name FROM users WHERE id = ?', [1]));
        $this->assertSame('Alice Updated', $rows[0]['name']);
    }

    public function testUpdateMultipleFields(): void
    {
        $affected = $this->db->update('users')
            ->fields(['name' => 'Updated', 'email' => 'updated@example.com'])
            ->condition('id', 1)
            ->execute();

        $this->assertSame(1, $affected);

        $rows = iterator_to_array($this->db->query('SELECT name, email FROM users WHERE id = ?', [1]));
        $this->assertSame('Updated', $rows[0]['name']);
        $this->assertSame('updated@example.com', $rows[0]['email']);
    }

    public function testUpdateWithNoMatchReturnsZero(): void
    {
        $affected = $this->db->update('users')
            ->fields(['name' => 'Nobody'])
            ->condition('id', 999)
            ->execute();

        $this->assertSame(0, $affected);
    }

    public function testUpdateWithComplexCondition(): void
    {
        $affected = $this->db->update('users')
            ->fields(['status' => 0])
            ->condition('id', [1, 2], 'IN')
            ->execute();

        $this->assertSame(2, $affected);

        $rows = iterator_to_array($this->db->query('SELECT status FROM users WHERE id IN (1, 2)'));
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row['status']);
        }
    }

    public function testUpdateWithNoFieldsThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->db->update('users')
            ->condition('id', 1)
            ->execute();
    }

    public function testFluentInterface(): void
    {
        $update = $this->db->update('users');

        $this->assertSame($update, $update->fields(['name' => 'Test']));
        $this->assertSame($update, $update->condition('id', 1));
    }
}
