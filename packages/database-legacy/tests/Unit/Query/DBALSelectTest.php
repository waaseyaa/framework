<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Query;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Query\DBALSelect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DBALSelect::class)]
final class DBALSelectTest extends TestCase
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

        $this->db->schema()->createTable('roles', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'role_name' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
            ],
            'primary key' => ['id'],
        ]);

        $this->db->schema()->createTable('user_roles', [
            'fields' => [
                'user_id' => ['type' => 'int', 'not null' => true],
                'role_id' => ['type' => 'int', 'not null' => true],
            ],
            'primary key' => ['user_id', 'role_id'],
        ]);

        // Seed data.
        $this->db->insert('users')->fields(['name', 'email', 'status'])->values(['Alice', 'alice@example.com', 1])->execute();
        $this->db->insert('users')->fields(['name', 'email', 'status'])->values(['Bob', null, 1])->execute();
        $this->db->insert('users')->fields(['name', 'email', 'status'])->values(['Charlie', 'charlie@example.com', 0])->execute();

        $this->db->insert('roles')->fields(['role_name'])->values(['admin'])->execute();
        $this->db->insert('roles')->fields(['role_name'])->values(['editor'])->execute();

        $this->db->insert('user_roles')->fields(['user_id', 'role_id'])->values([1, 1])->execute();
        $this->db->insert('user_roles')->fields(['user_id', 'role_id'])->values([1, 2])->execute();
        $this->db->insert('user_roles')->fields(['user_id', 'role_id'])->values([2, 2])->execute();
    }

    public function testSelectAllFromTable(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(3, $rows);
    }

    public function testSelectSpecificFields(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name', 'email'])
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertArrayHasKey('email', $rows[0]);
    }

    public function testSelectWithAddField(): void
    {
        $result = $this->db->select('users', 'u')
            ->addField('u', 'name', 'user_name')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]['user_name']);
    }

    public function testSelectWithEqualCondition(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->condition('u.name', 'Alice')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testSelectWithNotEqualCondition(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->condition('u.name', 'Alice', '<>')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(2, $rows);
    }

    public function testSelectWithInCondition(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->condition('u.id', [1, 3], 'IN')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
    }

    public function testSelectWithNotInCondition(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->condition('u.id', [1, 3], 'NOT IN')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]['name']);
    }

    public function testSelectWithIsNull(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->isNull('u.email')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]['name']);
    }

    public function testSelectWithIsNotNull(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->isNotNull('u.email')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(2, $rows);
    }

    public function testSelectWithBetween(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->condition('u.id', [1, 2], 'BETWEEN')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(2, $rows);
    }

    public function testSelectWithMultipleConditions(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->condition('u.status', 1)
            ->isNotNull('u.email')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testSelectWithOrderByAsc(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->orderBy('u.name', 'ASC')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
        $this->assertSame('Charlie', $rows[2]['name']);
    }

    public function testSelectWithOrderByDesc(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->orderBy('u.name', 'DESC')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertSame('Charlie', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
        $this->assertSame('Alice', $rows[2]['name']);
    }

    public function testSelectWithRange(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->orderBy('u.id', 'ASC')
            ->range(0, 2)
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
    }

    public function testSelectWithRangeOffset(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->orderBy('u.id', 'ASC')
            ->range(1, 2)
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]['name']);
        $this->assertSame('Charlie', $rows[1]['name']);
    }

    public function testSelectWithInnerJoin(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->addField('r', 'role_name')
            ->join('user_roles', 'ur', 'u.id = ur.user_id')
            ->join('roles', 'r', 'ur.role_id = r.id')
            ->orderBy('u.name', 'ASC')
            ->orderBy('r.role_name', 'ASC')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('admin', $rows[0]['role_name']);
        $this->assertSame('Alice', $rows[1]['name']);
        $this->assertSame('editor', $rows[1]['role_name']);
        $this->assertSame('Bob', $rows[2]['name']);
        $this->assertSame('editor', $rows[2]['role_name']);
    }

    public function testSelectWithLeftJoin(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->addField('ur', 'role_id')
            ->leftJoin('user_roles', 'ur', 'u.id = ur.user_id')
            ->condition('u.name', 'Charlie')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]['name']);
        $this->assertNull($rows[0]['role_id']);
    }

    public function testCountQuery(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u')
            ->condition('u.status', 1)
            ->countQuery()
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertSame(2, (int) $rows[0]['count']);
    }

    public function testCountQueryIgnoresOrderBy(): void
    {
        // This should not throw even though orderBy references specific fields.
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->orderBy('u.name', 'ASC')
            ->countQuery()
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertSame(3, (int) $rows[0]['count']);
    }

    public function testSelectWithNoFieldsSelectsAll(): void
    {
        $result = $this->db->select('users')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(3, $rows);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('email', $rows[0]);
        $this->assertArrayHasKey('status', $rows[0]);
    }

    public function testSelectWithGreaterThanCondition(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->condition('u.id', 1, '>')
            ->orderBy('u.id', 'ASC')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]['name']);
    }

    public function testSelectWithLikeCondition(): void
    {
        $result = $this->db->select('users', 'u')
            ->fields('u', ['name'])
            ->condition('u.name', 'A%', 'LIKE')
            ->execute();

        $rows = iterator_to_array($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testInvalidOrderDirectionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->db->select('users', 'u')
            ->orderBy('u.name', 'INVALID');
    }

    public function testFluentInterface(): void
    {
        $select = $this->db->select('users', 'u');

        $this->assertSame($select, $select->fields('u'));
        $this->assertSame($select, $select->condition('u.id', 1));
        $this->assertSame($select, $select->isNull('u.email'));
        $this->assertSame($select, $select->isNotNull('u.email'));
        $this->assertSame($select, $select->orderBy('u.id'));
        $this->assertSame($select, $select->range(0, 10));
        $this->assertSame($select, $select->join('roles', 'r', '1=1'));
        $this->assertSame($select, $select->leftJoin('roles', 'r2', '1=1'));
        $this->assertSame($select, $select->addField('u', 'name'));
    }

    public function testCountQueryReturnsNewInstance(): void
    {
        $select = $this->db->select('users', 'u')->fields('u');
        $count = $select->countQuery();

        $this->assertNotSame($select, $count);
    }
}
