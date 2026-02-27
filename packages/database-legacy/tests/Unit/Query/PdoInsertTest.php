<?php

declare(strict_types=1);

namespace Aurora\Database\Tests\Unit\Query;

use Aurora\Database\PdoDatabase;
use Aurora\Database\Query\PdoInsert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdoInsert::class)]
final class PdoInsertTest extends TestCase
{
    private PdoDatabase $db;

    protected function setUp(): void
    {
        $this->db = PdoDatabase::createSqlite();

        $this->db->schema()->createTable('users', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'email' => ['type' => 'varchar', 'length' => 255],
            ],
            'primary key' => ['id'],
        ]);
    }

    public function testInsertWithFieldsAndValues(): void
    {
        $id = $this->db->insert('users')
            ->fields(['name', 'email'])
            ->values(['Alice', 'alice@example.com'])
            ->execute();

        $this->assertSame('1', $id);

        $result = $this->db->query('SELECT * FROM users WHERE id = ?', [$id]);
        $row = iterator_to_array($result)[0];

        $this->assertSame('Alice', $row['name']);
        $this->assertSame('alice@example.com', $row['email']);
    }

    public function testInsertWithAssociativeValues(): void
    {
        $id = $this->db->insert('users')
            ->fields(['name', 'email'])
            ->values(['name' => 'Bob', 'email' => 'bob@example.com'])
            ->execute();

        $result = $this->db->query('SELECT * FROM users WHERE id = ?', [$id]);
        $row = iterator_to_array($result)[0];

        $this->assertSame('Bob', $row['name']);
        $this->assertSame('bob@example.com', $row['email']);
    }

    public function testInsertInferFieldsFromAssociativeValues(): void
    {
        $id = $this->db->insert('users')
            ->values(['name' => 'Carol', 'email' => 'carol@example.com'])
            ->execute();

        $result = $this->db->query('SELECT * FROM users WHERE id = ?', [$id]);
        $row = iterator_to_array($result)[0];

        $this->assertSame('Carol', $row['name']);
        $this->assertSame('carol@example.com', $row['email']);
    }

    public function testInsertMultipleRows(): void
    {
        $this->db->insert('users')
            ->fields(['name', 'email'])
            ->values(['Alice', 'alice@example.com'])
            ->values(['Bob', 'bob@example.com'])
            ->execute();

        $result = $this->db->query('SELECT COUNT(*) as cnt FROM users');
        $count = (int) iterator_to_array($result)[0]['cnt'];

        $this->assertSame(2, $count);
    }

    public function testInsertReturnsLastInsertId(): void
    {
        $id1 = $this->db->insert('users')
            ->fields(['name', 'email'])
            ->values(['Alice', 'alice@example.com'])
            ->execute();

        $id2 = $this->db->insert('users')
            ->fields(['name', 'email'])
            ->values(['Bob', 'bob@example.com'])
            ->execute();

        $this->assertSame('1', $id1);
        $this->assertSame('2', $id2);
    }

    public function testInsertWithNullValue(): void
    {
        $id = $this->db->insert('users')
            ->fields(['name', 'email'])
            ->values(['Alice', null])
            ->execute();

        $result = $this->db->query('SELECT * FROM users WHERE id = ?', [$id]);
        $row = iterator_to_array($result)[0];

        $this->assertSame('Alice', $row['name']);
        $this->assertNull($row['email']);
    }

    public function testInsertWithNoFieldsThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->db->insert('users')->execute();
    }

    public function testFluentInterface(): void
    {
        $insert = $this->db->insert('users');

        $this->assertSame($insert, $insert->fields(['name', 'email']));
        $this->assertSame($insert, $insert->values(['Alice', 'alice@example.com']));
    }
}
