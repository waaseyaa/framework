<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

#[CoversClass(SqlEntityQuery::class)]
final class SqlEntityQueryEdgeCaseTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
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

        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();
        $handler->addFieldColumns([
            'priority' => [
                'type' => 'int',
                'not null' => false,
            ],
        ]);

        $this->insertRow(1, 'uuid-1', 'article', 'Bravo Article', 'en', 10);
        $this->insertRow(2, 'uuid-2', 'article', 'Alpha Article', 'en', 20);
        $this->insertRow(3, 'uuid-3', 'page', 'Charlie Page', 'fr', 10);
        $this->insertRow(4, 'uuid-4', 'page', 'Delta Page', 'en', 30);
    }

    private function insertRow(
        int $id,
        string $uuid,
        string $bundle,
        string $label,
        string $langcode,
        ?int $priority,
    ): void {
        $fields = ['id', 'uuid', 'bundle', 'label', 'langcode', 'priority'];
        $this->database->insert('test_entity')
            ->fields($fields)
            ->values([$id, $uuid, $bundle, $label, $langcode, $priority])
            ->execute();
    }

    #[Test]
    public function queryWithEmptyConditionsReturnsAllEntities(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->execute();

        $this->assertCount(4, $ids);
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertContains(3, $ids);
        $this->assertContains(4, $ids);
    }

    #[Test]
    public function queryWithNoMatchesReturnsEmptyArray(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('bundle', 'nonexistent_bundle')->execute();

        $this->assertSame([], $ids);
    }

    #[Test]
    public function querySortByMultipleFields(): void
    {
        // Sort by bundle ASC, then by label ASC within each bundle.
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query
            ->sort('bundle', 'ASC')
            ->sort('label', 'ASC')
            ->execute();

        // Articles: Alpha (2), Bravo (1). Pages: Charlie (3), Delta (4).
        $this->assertSame([2, 1, 3, 4], $ids);
    }

    #[Test]
    public function querySortByMultipleFieldsMixedDirections(): void
    {
        // Sort by bundle ASC, then by priority DESC within each bundle.
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query
            ->sort('bundle', 'ASC')
            ->sort('priority', 'DESC')
            ->execute();

        // Articles: priority 20 (2), 10 (1). Pages: priority 30 (4), 10 (3).
        $this->assertSame([2, 1, 4, 3], $ids);
    }

    #[Test]
    public function queryRangeWithZeroOffset(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->sort('id', 'ASC')->range(0, 2)->execute();

        $this->assertCount(2, $ids);
        $this->assertSame([1, 2], $ids);
    }

    #[Test]
    public function queryRangeWithLargeOffsetReturnsEmpty(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->sort('id', 'ASC')->range(100, 10)->execute();

        $this->assertSame([], $ids);
    }

    #[Test]
    public function queryRangeWithLimitOfOne(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->sort('id', 'ASC')->range(0, 1)->execute();

        $this->assertCount(1, $ids);
        $this->assertSame([1], $ids);
    }

    #[Test]
    public function queryCountWithNoConditions(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $result = $query->count()->execute();

        $this->assertSame([4], $result);
    }

    #[Test]
    public function queryCountWithNoMatchesReturnsZero(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $result = $query->condition('bundle', 'nonexistent')->count()->execute();

        $this->assertSame([0], $result);
    }

    #[Test]
    public function queryFluentInterfaceReturnsSameInstance(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);

        $result = $query
            ->condition('bundle', 'article')
            ->sort('id', 'ASC')
            ->range(0, 10)
            ->accessCheck(false);

        $this->assertSame($query, $result);
    }

    #[Test]
    public function queryWithEmptyArrayInOperatorReturnsNoResults(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('id', [], 'IN')->execute();

        // Empty IN() matches nothing — no rows satisfy "id IN ()".
        $this->assertSame([], $ids);
    }

    #[Test]
    public function queryWithEmptyArrayNotInOperatorReturnsNoResults(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('id', [], 'NOT IN')->execute();

        // DBAL produces empty parameter list for NOT IN(), which matches nothing.
        // This is a known DBAL behavior — callers should guard against empty arrays.
        $this->assertSame([], $ids);
    }

    #[Test]
    public function queryOnDataFieldWithOperators(): void
    {
        // Insert entities via raw SQL with _data JSON containing a "score" field.
        $this->database->insert('test_entity')
            ->fields(['id', 'uuid', 'bundle', 'label', 'langcode', 'priority', '_data'])
            ->values([10, 'uuid-10', 'article', 'Scored A', 'en', 5, '{"score": 85}'])
            ->execute();
        $this->database->insert('test_entity')
            ->fields(['id', 'uuid', 'bundle', 'label', 'langcode', 'priority', '_data'])
            ->values([11, 'uuid-11', 'article', 'Scored B', 'en', 5, '{"score": 42}'])
            ->execute();

        // Query on a _data field using > operator.
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('score', 50, '>')->execute();

        $this->assertCount(1, $ids);
        $this->assertContains(10, $ids);
    }

    #[Test]
    public function queryContainsOnDataField(): void
    {
        // Insert entity with _data JSON containing a "tag" field.
        $this->database->insert('test_entity')
            ->fields(['id', 'uuid', 'bundle', 'label', 'langcode', 'priority', '_data'])
            ->values([12, 'uuid-12', 'article', 'Tagged', 'en', 5, '{"tag": "important-note"}'])
            ->execute();

        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('tag', 'important', 'CONTAINS')->execute();

        $this->assertCount(1, $ids);
        $this->assertContains(12, $ids);
    }

    #[Test]
    public function querySortOnDataField(): void
    {
        // Insert entities with _data JSON containing a "weight" field.
        $this->database->insert('test_entity')
            ->fields(['id', 'uuid', 'bundle', 'label', 'langcode', 'priority', '_data'])
            ->values([20, 'uuid-20', 'article', 'Heavy', 'en', 5, '{"weight": 100}'])
            ->execute();
        $this->database->insert('test_entity')
            ->fields(['id', 'uuid', 'bundle', 'label', 'langcode', 'priority', '_data'])
            ->values([21, 'uuid-21', 'article', 'Light', 'en', 5, '{"weight": 1}'])
            ->execute();

        // Sort by data field, filtering to only our inserted rows.
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query
            ->condition('id', [20, 21], 'IN')
            ->sort('weight', 'ASC')
            ->execute();

        $this->assertSame([21, 20], $ids);
    }
}
