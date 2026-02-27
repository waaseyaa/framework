<?php

declare(strict_types=1);

namespace Aurora\Database\Tests\Unit\Query;

use Aurora\Database\PdoDatabase;
use Aurora\Database\Query\PdoUpdate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoUpdate::class)]
final class PdoUpdateTest extends TestCase
{
    private PdoDatabase $db;

    protected function setUp(): void
    {
        $this->db = PdoDatabase::createSqlite();

        $this->db->schema()->createTable('users', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
            ],
            'primary key' => ['id'],
        ]);

        $this->db->insert('users')->fields(['name', 'status'])->values(['Alice', 1])->execute();
        $this->db->insert('users')->fields(['name', 'status'])->values(['Bob', 1])->execute();
        $this->db->insert('users')->fields(['name', 'status'])->values(['Charlie', 0])->execute();
    }

    public function testUpdateWithCondition(): void
    {
        $affected = $this->db->update('users')
            ->fields(['name' => 'Alicia'])
            ->condition('id', 1)
            ->execute();

        $this->assertSame(1, $affected);

        $result = $this->db->query('SELECT name FROM users WHERE id = ?', [1]);
        $row = iterator_to_array($result)[0];

        $this->assertSame('Alicia', $row['name']);
    }

    public function testUpdateMultipleRows(): void
    {
        $affected = $this->db->update('users')
            ->fields(['status' => 0])
            ->condition('status', 1)
            ->execute();

        $this->assertSame(2, $affected);

        $result = $this->db->query('SELECT COUNT(*) as cnt FROM users WHERE status = 0');
        $count = (int) iterator_to_array($result)[0]['cnt'];

        $this->assertSame(3, $count);
    }

    public function testUpdateWithMultipleFields(): void
    {
        $affected = $this->db->update('users')
            ->fields(['name' => 'Updated', 'status' => 0])
            ->condition('id', 2)
            ->execute();

        $this->assertSame(1, $affected);

        $result = $this->db->query('SELECT name, status FROM users WHERE id = ?', [2]);
        $row = iterator_to_array($result)[0];

        $this->assertSame('Updated', $row['name']);
        $this->assertSame(0, (int) $row['status']);
    }

    public function testUpdateWithMultipleConditions(): void
    {
        $affected = $this->db->update('users')
            ->fields(['name' => 'Updated'])
            ->condition('status', 1)
            ->condition('id', 1, '>')
            ->execute();

        $this->assertSame(1, $affected);

        $result = $this->db->query('SELECT name FROM users WHERE id = ?', [2]);
        $row = iterator_to_array($result)[0];

        $this->assertSame('Updated', $row['name']);
    }

    public function testUpdateWithInCondition(): void
    {
        $affected = $this->db->update('users')
            ->fields(['status' => 99])
            ->condition('id', [1, 3], 'IN')
            ->execute();

        $this->assertSame(2, $affected);
    }

    public function testUpdateWithoutConditionUpdatesAll(): void
    {
        $affected = $this->db->update('users')
            ->fields(['status' => 42])
            ->execute();

        $this->assertSame(3, $affected);
    }

    public function testUpdateNoFieldsThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->db->update('users')
            ->condition('id', 1)
            ->execute();
    }

    public function testUpdateReturnsZeroWhenNoMatch(): void
    {
        $affected = $this->db->update('users')
            ->fields(['name' => 'Nobody'])
            ->condition('id', 999)
            ->execute();

        $this->assertSame(0, $affected);
    }

    public function testFluentInterface(): void
    {
        $update = $this->db->update('users');

        $this->assertSame($update, $update->fields(['name' => 'Test']));
        $this->assertSame($update, $update->condition('id', 1));
    }
}
