<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Query;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Query\DBALDelete;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DBALDelete::class)]
final class DBALDeleteTest extends TestCase
{
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();

        $this->db->schema()->createTable('users', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
            ],
            'primary key' => ['id'],
        ]);

        $this->db->insert('users')->values(['name' => 'Alice', 'status' => 1])->execute();
        $this->db->insert('users')->values(['name' => 'Bob', 'status' => 1])->execute();
        $this->db->insert('users')->values(['name' => 'Charlie', 'status' => 0])->execute();
    }

    public function testDeleteWithSimpleCondition(): void
    {
        $affected = $this->db->delete('users')
            ->condition('id', 1)
            ->execute();

        $this->assertSame(1, $affected);

        $rows = iterator_to_array($this->db->query('SELECT COUNT(*) as cnt FROM users'));
        $this->assertSame(2, (int) $rows[0]['cnt']);
    }

    public function testDeleteMultipleRows(): void
    {
        $affected = $this->db->delete('users')
            ->condition('status', 1)
            ->execute();

        $this->assertSame(2, $affected);

        $rows = iterator_to_array($this->db->query('SELECT COUNT(*) as cnt FROM users'));
        $this->assertSame(1, (int) $rows[0]['cnt']);
    }

    public function testDeleteWithNoMatchReturnsZero(): void
    {
        $affected = $this->db->delete('users')
            ->condition('id', 999)
            ->execute();

        $this->assertSame(0, $affected);
    }

    public function testDeleteWithComplexCondition(): void
    {
        $affected = $this->db->delete('users')
            ->condition('id', [1, 2], 'IN')
            ->execute();

        $this->assertSame(2, $affected);

        $rows = iterator_to_array($this->db->query('SELECT COUNT(*) as cnt FROM users'));
        $this->assertSame(1, (int) $rows[0]['cnt']);
    }

    public function testDeleteAllRows(): void
    {
        $affected = $this->db->delete('users')
            ->execute();

        $this->assertSame(3, $affected);
    }

    public function testFluentInterface(): void
    {
        $delete = $this->db->delete('users');

        $this->assertSame($delete, $delete->condition('id', 1));
    }
}
