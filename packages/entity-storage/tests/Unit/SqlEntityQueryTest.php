<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Tests\Unit;

use Aurora\Database\PdoDatabase;
use Aurora\Entity\EntityType;
use Aurora\EntityStorage\SqlEntityQuery;
use Aurora\EntityStorage\SqlSchemaHandler;
use Aurora\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\TestCase;

final class SqlEntityQueryTest extends TestCase
{
    private PdoDatabase $database;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->database = PdoDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );

        // Create the table and add a status column.
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->addFieldColumns([
            'status' => [
                'type' => 'int',
                'not null' => false,
            ],
        ]);

        // Insert test data.
        $this->insertRow(1, 'uuid-1', 'article', 'First Article', 'en', 1);
        $this->insertRow(2, 'uuid-2', 'article', 'Second Article', 'en', 0);
        $this->insertRow(3, 'uuid-3', 'page', 'A Page', 'en', 1);
        $this->insertRow(4, 'uuid-4', 'article', 'Third Article', 'fr', null);
    }

    private function insertRow(int $id, string $uuid, string $bundle, string $label, string $langcode, ?int $status): void
    {
        $fields = ['id', 'uuid', 'bundle', 'label', 'langcode', 'status'];
        $this->database->insert('test_entity')
            ->fields($fields)
            ->values([$id, $uuid, $bundle, $label, $langcode, $status])
            ->execute();
    }

    public function testConditionEquals(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('bundle', 'article')->execute();

        $this->assertCount(3, $ids);
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertContains(4, $ids);
    }

    public function testConditionIn(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('id', [1, 3], 'IN')->execute();

        $this->assertCount(2, $ids);
        $this->assertContains(1, $ids);
        $this->assertContains(3, $ids);
    }

    public function testExists(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->exists('status')->execute();

        // Rows 1, 2, 3 have non-null status; row 4 has null.
        $this->assertCount(3, $ids);
        $this->assertNotContains(4, $ids);
    }

    public function testNotExists(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->notExists('status')->execute();

        // Only row 4 has null status.
        $this->assertCount(1, $ids);
        $this->assertContains(4, $ids);
    }

    public function testSortAscending(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->sort('label', 'ASC')->execute();

        $this->assertSame([3, 1, 2, 4], $ids);
    }

    public function testSortDescending(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->sort('label', 'DESC')->execute();

        $this->assertSame([4, 2, 1, 3], $ids);
    }

    public function testRange(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->sort('id', 'ASC')->range(1, 2)->execute();

        $this->assertCount(2, $ids);
        $this->assertSame([2, 3], $ids);
    }

    public function testCount(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $result = $query->condition('bundle', 'article')->count()->execute();

        $this->assertSame([3], $result);
    }

    public function testComplexQuery(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query
            ->condition('bundle', 'article')
            ->condition('langcode', 'en')
            ->sort('label', 'DESC')
            ->range(0, 1)
            ->execute();

        // Articles in 'en': ids 1, 2. Sorted by label DESC: "Second Article" (2), "First Article" (1).
        $this->assertCount(1, $ids);
        $this->assertSame([2], $ids);
    }

    public function testAccessCheckIsNoOp(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $result = $query->accessCheck(true);

        // Should return same instance (fluent).
        $this->assertSame($query, $result);

        // Should still execute normally.
        $ids = $result->sort('id', 'ASC')->execute();
        $this->assertCount(4, $ids);
    }
}
