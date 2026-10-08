<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\Listing\ListingResolver;

/**
 * Concrete realisation of {@see ListingResolverContract} backed by
 * {@see SqlStorageDriver} on an in-memory SQLite database via
 * {@see DBALDatabase::createSqlite()}.
 */
#[CoversClass(ListingResolver::class)]
final class SqliteListingResolverTest extends ListingResolverContract
{
    protected function createDriver(): EntityStorageDriverInterface
    {
        $db = DBALDatabase::createSqlite();
        $this->ensureArticleSchema($db, translatable: false);

        return new SqlStorageDriver(new SingleConnectionResolver($db));
    }

    protected function createTranslatableDriver(): EntityStorageDriverInterface
    {
        $db = DBALDatabase::createSqlite();
        $this->ensureArticleSchema($db, translatable: true);

        return new SqlStorageDriver(new SingleConnectionResolver($db));
    }

    protected function seed(
        EntityStorageDriverInterface $driver,
        string $entityType,
        array $rows,
    ): void {
        \assert($driver instanceof SqlStorageDriver);
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $driver->write($entityType, $id, $row);
        }
    }

    private function ensureArticleSchema(\Waaseyaa\Database\DatabaseInterface $db, bool $translatable): void
    {
        $schema = $db->schema();
        if (!$schema->tableExists('article')) {
            $columns = [
                ['name' => 'id', 'type' => 'varchar', 'length' => 32, 'not null' => true, 'primary key' => true],
                ['name' => 'title', 'type' => 'varchar', 'length' => 255, 'not null' => false],
                ['name' => 'status', 'type' => 'int', 'not null' => false],
                ['name' => 'weight', 'type' => 'int', 'not null' => false],
                ['name' => '_data', 'type' => 'text', 'not null' => false],
            ];
            if ($translatable) {
                $columns[] = ['name' => 'langcode', 'type' => 'varchar', 'length' => 12, 'not null' => false];
            }
            $schema->createTable('article', ['fields' => $this->indexByName($columns)]);
        }
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @return array<string, array<string, mixed>>
     */
    private function indexByName(array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            /** @var string $name */
            $name = $col['name'];
            $out[$name] = $col;
        }

        return $out;
    }
}
