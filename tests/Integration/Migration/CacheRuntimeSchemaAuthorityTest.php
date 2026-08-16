<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Migration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\Backend\DatabaseBackend;

#[CoversNothing]
final class CacheRuntimeSchemaAuthorityTest extends TestCase
{
    #[Test]
    public function a_cache_read_refuses_missing_schema_without_creating_a_bin_table(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $backend = new DatabaseBackend($pdo, 'cache_dynamic');

        try {
            $backend->get('missing');
            self::fail('Missing cache schema must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB106]', $exception->getMessage());
        }

        self::assertSame([], $this->tables($pdo));
    }

    #[Test]
    public function removing_a_bin_deletes_rows_without_dropping_schema(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE cache_items (
            bin VARCHAR(128) NOT NULL,
            cid VARCHAR(255) NOT NULL,
            data BLOB NOT NULL,
            expire INTEGER NOT NULL DEFAULT -1,
            created INTEGER NOT NULL DEFAULT 0,
            tags TEXT NOT NULL DEFAULT \'\',
            valid INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (bin, cid)
        )');
        $backend = new DatabaseBackend($pdo, 'cache_dynamic');
        $backend->set('item', 'value');

        $backend->removeBin();

        self::assertSame(['cache_items'], $this->tables($pdo));
        self::assertFalse($backend->get('item'));
        self::assertSame(['cache_items'], $this->tables($pdo));
    }

    /** @return list<string> */
    private function tables(\PDO $pdo): array
    {
        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
        self::assertNotFalse($statement);

        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }
}
