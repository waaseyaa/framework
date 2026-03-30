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
final class SqlEntityQueryOperatorTest extends TestCase
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
            'status' => [
                'type' => 'int',
                'not null' => false,
            ],
            'priority' => [
                'type' => 'int',
                'not null' => false,
            ],
        ]);

        // Insert test data with varying status and priority values.
        $this->insertRow(1, 'uuid-1', 'article', 'First Article', 'en', 1, 10);
        $this->insertRow(2, 'uuid-2', 'article', 'Second Article', 'en', 0, 20);
        $this->insertRow(3, 'uuid-3', 'page', 'A Page', 'en', 1, 30);
        $this->insertRow(4, 'uuid-4', 'article', 'Third Article', 'fr', null, null);
        $this->insertRow(5, 'uuid-5', 'page', 'Another Page', 'en', 0, 15);
    }

    private function insertRow(
        int $id,
        string $uuid,
        string $bundle,
        string $label,
        string $langcode,
        ?int $itemStatus,
        ?int $priority,
    ): void {
        $fields = ['id', 'uuid', 'bundle', 'label', 'langcode', 'status', 'priority'];
        $this->database->insert('test_entity')
            ->fields($fields)
            ->values([$id, $uuid, $bundle, $label, $langcode, $itemStatus, $priority])
            ->execute();
    }

    #[Test]
    public function queryOperatorNotEqual(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('bundle', 'article', '!=')->execute();

        // Rows 3 and 5 are pages.
        $this->assertCount(2, $ids);
        $this->assertContains(3, $ids);
        $this->assertContains(5, $ids);
    }

    #[Test]
    public function queryOperatorStartsWith(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('label', 'A', 'STARTS_WITH')->execute();

        // "A Page" (3) and "Another Page" (5) start with "A".
        $this->assertCount(2, $ids);
        $this->assertContains(3, $ids);
        $this->assertContains(5, $ids);
    }

    #[Test]
    public function queryOperatorStartsWithEscapesWildcards(): void
    {
        // Insert a row with a percent sign in the label.
        $this->insertRow(6, 'uuid-6', 'article', '100% Complete', 'en', 1, 50);

        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('label', '100%', 'STARTS_WITH')->execute();

        // Only the row with "100% Complete" should match, not "10..." rows.
        $this->assertCount(1, $ids);
        $this->assertContains(6, $ids);
    }

    #[Test]
    public function queryOperatorLessThan(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('priority', 20, '<')->execute();

        // Rows with priority < 20: id 1 (10), id 5 (15).
        $this->assertCount(2, $ids);
        $this->assertContains(1, $ids);
        $this->assertContains(5, $ids);
    }

    #[Test]
    public function queryOperatorGreaterThan(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('priority', 20, '>')->execute();

        // Rows with priority > 20: id 3 (30).
        $this->assertCount(1, $ids);
        $this->assertContains(3, $ids);
    }

    #[Test]
    public function queryOperatorLessThanOrEqual(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('priority', 20, '<=')->execute();

        // Rows with priority <= 20: id 1 (10), id 2 (20), id 5 (15).
        $this->assertCount(3, $ids);
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertContains(5, $ids);
    }

    #[Test]
    public function queryOperatorGreaterThanOrEqual(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('priority', 20, '>=')->execute();

        // Rows with priority >= 20: id 2 (20), id 3 (30).
        $this->assertCount(2, $ids);
        $this->assertContains(2, $ids);
        $this->assertContains(3, $ids);
    }

    #[Test]
    public function queryOperatorIsNull(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->notExists('status')->execute();

        // Only row 4 has null status.
        $this->assertCount(1, $ids);
        $this->assertContains(4, $ids);
    }

    #[Test]
    public function queryOperatorIsNotNull(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->exists('status')->execute();

        // Rows 1, 2, 3, 5 have non-null status.
        $this->assertCount(4, $ids);
        $this->assertNotContains(4, $ids);
    }

    #[Test]
    public function queryOperatorIsNullViaCondition(): void
    {
        // Test IS NULL via the condition() method with explicit operator.
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('priority', null, 'IS NULL')->execute();

        // Row 4 has null priority.
        $this->assertCount(1, $ids);
        $this->assertContains(4, $ids);
    }

    #[Test]
    public function queryOperatorIsNotNullViaCondition(): void
    {
        // Test IS NOT NULL via the condition() method with explicit operator.
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('priority', null, 'IS NOT NULL')->execute();

        // Rows 1, 2, 3, 5 have non-null priority.
        $this->assertCount(4, $ids);
        $this->assertNotContains(4, $ids);
    }

    #[Test]
    public function queryOperatorNotIn(): void
    {
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('id', [1, 2, 4], 'NOT IN')->execute();

        // Rows 3 and 5 are not in the exclusion list.
        $this->assertCount(2, $ids);
        $this->assertContains(3, $ids);
        $this->assertContains(5, $ids);
    }

    #[Test]
    public function queryOperatorContainsEscapesWildcards(): void
    {
        // Insert a row with underscore in the label.
        $this->insertRow(7, 'uuid-7', 'article', 'test_value here', 'en', 1, 5);

        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query->condition('label', 'test_value', 'CONTAINS')->execute();

        // Only the row with literal "test_value" should match.
        $this->assertCount(1, $ids);
        $this->assertContains(7, $ids);
    }

    #[Test]
    public function queryOperatorsCombined(): void
    {
        // Combine multiple operators in a single query.
        $query = new SqlEntityQuery($this->entityType, $this->database);
        $ids = $query
            ->condition('bundle', 'article')
            ->condition('priority', 15, '>')
            ->exists('status')
            ->execute();

        // Articles with priority > 15 and non-null status: id 2 (priority 20, status 0).
        $this->assertCount(1, $ids);
        $this->assertContains(2, $ids);
    }
}
