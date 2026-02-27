<?php

declare(strict_types=1);

namespace Aurora\Database\Tests\Unit\Query;

use Aurora\Database\PdoDatabase;
use Aurora\Database\Query\PdoDelete;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoDelete::class)]
final class PdoDeleteTest extends TestCase
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

    public function testDeleteWithCondition(): void
    {
        $affected = $this->db->delete('users')
            ->condition('id', 1)
            ->execute();

        $this->assertSame(1, $affected);

        $result = $this->db->query('SELECT COUNT(*) as cnt FROM users');
        $count = (int) iterator_to_array($result)[0]['cnt'];

        $this->assertSame(2, $count);
    }

    public function testDeleteWithMultipleConditions(): void
    {
        $affected = $this->db->delete('users')
            ->condition('status', 1)
            ->condition('name', 'Alice')
            ->execute();

        $this->assertSame(1, $affected);
    }

    public function testDeleteWithInCondition(): void
    {
        $affected = $this->db->delete('users')
            ->condition('id', [1, 2], 'IN')
            ->execute();

        $this->assertSame(2, $affected);

        $result = $this->db->query('SELECT name FROM users');
        $rows = iterator_to_array($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]['name']);
    }

    public function testDeleteWithNotEqualCondition(): void
    {
        $affected = $this->db->delete('users')
            ->condition('status', 1, '<>')
            ->execute();

        $this->assertSame(1, $affected);
    }

    public function testDeleteAllRows(): void
    {
        $affected = $this->db->delete('users')
            ->execute();

        $this->assertSame(3, $affected);

        $result = $this->db->query('SELECT COUNT(*) as cnt FROM users');
        $count = (int) iterator_to_array($result)[0]['cnt'];

        $this->assertSame(0, $count);
    }

    public function testDeleteReturnsZeroWhenNoMatch(): void
    {
        $affected = $this->db->delete('users')
            ->condition('id', 999)
            ->execute();

        $this->assertSame(0, $affected);
    }

    public function testFluentInterface(): void
    {
        $delete = $this->db->delete('users');

        $this->assertSame($delete, $delete->condition('id', 1));
    }
}
