<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeServiceProvider;

/**
 * CW-v1 WP-2 Task 2.1 — pins that Node's real registered `EntityType` (as
 * built by {@see NodeServiceProvider}, with `revisionable: true` /
 * `revisionDefault: true`) actually materializes a `node_revision` table
 * (live single-underscore dialect, {@see SqlSchemaHandler::getRevisionTableName()})
 * and that an ordinary save writes a row into it — not just that the flags
 * are set on the definition (covered by NodeServiceProviderTest).
 *
 * Exercises the storage plumbing directly (schema handler + drivers +
 * repository), same shape as
 * EntityRepositoryOptimisticLockingTest/EntityRepositoryRevisionTest, rather
 * than through the kernel, so this stays a fast, dependency-light unit test.
 */
#[CoversNothing]
final class NodeRevisionStorageTest extends TestCase
{
    private DBALDatabase $db;
    private EntityTypeInterface $nodeEntityType;
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $provider = new NodeServiceProvider();
        $provider->register();
        $this->nodeEntityType = $provider->getEntityTypes()[0];

        $this->db = DBALDatabase::createSqlite();

        $handler = new SqlSchemaHandler($this->nodeEntityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver, $this->nodeEntityType->getKeys()['id']);
        $revisionDriver = new RevisionableStorageDriver($resolver, $this->nodeEntityType);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $this->repo = new EntityRepository(
            $this->nodeEntityType,
            $driver,
            $dispatcher,
            $revisionDriver,
            $this->db,
        );
    }

    private function revisionRowCount(): int
    {
        foreach ($this->db->query('SELECT COUNT(*) AS c FROM node_revision') as $row) {
            return (int) $row['c'];
        }

        return -1;
    }

    #[Test]
    public function ensure_revision_table_creates_the_single_underscore_dialect_table(): void
    {
        foreach ($this->db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'node_revision'") as $row) {
            $this->assertSame('node_revision', $row['name']);

            return;
        }

        $this->fail('Expected a "node_revision" table to exist after ensureRevisionTable().');
    }

    #[Test]
    public function saving_a_new_node_writes_a_node_revision_row(): void
    {
        $node = new Node(['title' => 'Hello', 'type' => 'article', 'slug' => 'hello']);
        $node->enforceIsNew();

        $this->repo->save($node);

        $this->assertSame(1, $this->revisionRowCount());
    }

    #[Test]
    public function a_second_save_creates_a_second_revision_by_default(): void
    {
        $node = new Node(['title' => 'Hello', 'type' => 'article', 'slug' => 'hello']);
        $node->enforceIsNew();
        $this->repo->save($node);

        $loaded = $this->repo->find((string) $node->id());
        \assert($loaded instanceof Node);
        $loaded->setTitle('Hello, updated');
        $this->repo->save($loaded);

        $this->assertSame(2, $this->revisionRowCount());
    }
}
