<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

#[CoversClass(RevisionableStorageDriver::class)]
final class RevisionableStorageDriverTest extends TestCase
{
    private DBALDatabase $db;
    private RevisionableStorageDriver $driver;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test Revisionable',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $this->driver = new RevisionableStorageDriver($resolver, $entityType);
    }

    #[Test]
    public function write_revision_creates_revision_row_and_returns_revision_id(): void
    {
        $revisionId = $this->driver->writeRevision('1', [
            'title' => 'Hello',
            'uuid' => 'abc-123',
        ], 'Initial creation');

        $this->assertSame(1, $revisionId);
    }

    #[Test]
    public function write_revision_increments_revision_id(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $rev2 = $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);

        $this->assertSame(2, $rev2);
    }

    #[Test]
    public function revision_ids_are_per_entity(): void
    {
        $this->driver->writeRevision('1', ['title' => 'A', 'uuid' => 'a'], null);
        $rev = $this->driver->writeRevision('2', ['title' => 'B', 'uuid' => 'b'], null);

        $this->assertSame(1, $rev);
    }

    #[Test]
    public function read_revision_returns_row(): void
    {
        $this->driver->writeRevision('1', ['title' => 'Hello', 'uuid' => 'a'], 'log msg');
        $row = $this->driver->readRevision('1', 1);

        $this->assertNotNull($row);
        $this->assertSame('Hello', $row['title']);
        $this->assertSame('log msg', $row['revision_log']);
        $this->assertArrayHasKey('revision_created', $row);
    }

    #[Test]
    public function read_revision_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->driver->readRevision('1', 99));
    }

    #[Test]
    public function get_latest_revision_id(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);

        $this->assertSame(2, $this->driver->getLatestRevisionId('1'));
    }

    #[Test]
    public function get_latest_revision_id_returns_null_for_no_revisions(): void
    {
        $this->assertNull($this->driver->getLatestRevisionId('999'));
    }

    #[Test]
    public function get_revision_ids_returns_ascending_list(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v3', 'uuid' => 'a'], null);

        $this->assertSame([1, 2, 3], $this->driver->getRevisionIds('1'));
    }

    #[Test]
    public function delete_revision_removes_row(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);

        $this->driver->deleteRevision('1', 1);

        $this->assertNull($this->driver->readRevision('1', 1));
        $this->assertNotNull($this->driver->readRevision('1', 2));
    }

    #[Test]
    public function update_revision_in_place(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], 'log');
        $this->driver->updateRevision('1', 1, ['title' => 'v1-updated', 'uuid' => 'a']);

        $row = $this->driver->readRevision('1', 1);
        $this->assertSame('v1-updated', $row['title']);
        // revision_log must be preserved during in-place update
        $this->assertSame('log', $row['revision_log']);
    }

    #[Test]
    public function delete_default_revision_throws(): void
    {
        // Insert a base row first, then create revision.
        $this->db->insert('test_revisionable')
            ->fields(['id', 'uuid', 'title', 'bundle', 'langcode', 'revision_id', '_data'])
            ->values(['id' => '1', 'uuid' => 'a', 'title' => 'v1', 'bundle' => 'test_revisionable', 'langcode' => 'en', 'revision_id' => 1, '_data' => '{}'])
            ->execute();
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot delete the default revision');
        $this->driver->deleteRevision('1', 1);
    }

    #[Test]
    public function read_multiple_revisions(): void
    {
        $this->driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null);
        $this->driver->writeRevision('1', ['title' => 'v3', 'uuid' => 'a'], null);

        $rows = $this->driver->readMultipleRevisions('1', [1, 3]);
        $this->assertCount(2, $rows);
    }
}
