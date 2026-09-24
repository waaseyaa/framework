<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Search\SearchIndexerInterface;
use Waaseyaa\Search\SearchServiceProvider;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;

/**
 * FW-SEARCH-PERSIST-01: the provider decides which database the indexer may
 * provision. Only a dedicated `search.database` file is provisioned, by
 * `search:reindex` (removeAll()); the application database, including a
 * `search.database` that names the application file, never is.
 */
#[CoversClass(SearchServiceProvider::class)]
final class SearchServiceProviderSchemaAuthorityTest extends TestCase
{
    private TemporarySqliteDatabase $application;

    protected function setUp(): void
    {
        $this->application = new TemporarySqliteDatabase();
    }

    protected function tearDown(): void
    {
        $this->application->remove();
    }

    /** @return iterable<string, array{?string}> */
    public static function applicationDatabaseConfigurations(): iterable
    {
        yield 'no search.database' => [null];
        yield 'search.database naming the application file' => ['database.sqlite'];
        yield 'search.database naming the application file another way' => ['./sub/../database.sqlite'];
    }

    #[Test]
    #[DataProvider('applicationDatabaseConfigurations')]
    public function reindexNeverProvisionsTheApplicationDatabase(?string $searchDatabase): void
    {
        $root = dirname($this->application->path());
        mkdir($root . '/sub');
        $indexer = $this->indexer($root, $searchDatabase);

        try {
            $indexer->removeAll();
            self::fail('removeAll() must refuse a missing projection on the application database.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('[SEARCH-DB002]', $e->getMessage());
        }

        self::assertSame([], $this->projectionTables($this->application->database()));
    }

    #[Test]
    public function aHardLinkToTheApplicationFileIsTheApplicationDatabase(): void
    {
        $root = dirname($this->application->path());
        self::assertTrue(link($this->application->path(), $root . '/hard.sqlite'));
        $indexer = $this->indexer($root, 'hard.sqlite');

        try {
            $indexer->removeAll();
            self::fail('removeAll() must refuse a missing projection on the application database.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('[SEARCH-DB002]', $e->getMessage());
        }

        self::assertSame([], $this->projectionTables($this->application->database()));
    }

    #[Test]
    public function reindexProvisionsADedicatedProjectionFileOnly(): void
    {
        $root = dirname($this->application->path());
        $indexer = $this->indexer($root, 'search.sqlite');

        $indexer->removeAll();
        unset($indexer);
        gc_collect_cycles();

        self::assertSame([], $this->projectionTables($this->application->database()), 'the application database is untouched');
        $dedicated = DBALDatabase::createSqlite($root . '/search.sqlite', 'testing');
        try {
            self::assertSame(['search_index', 'search_metadata'], $this->projectionTables($dedicated));
        } finally {
            $dedicated->getConnection()->close();
        }
    }

    private function indexer(string $root, ?string $searchDatabase): SearchIndexerInterface
    {
        $config = ['environment' => 'testing'];
        if ($searchDatabase !== null) {
            $config['search'] = ['database' => $searchDatabase];
        }

        $provider = new SearchServiceProvider();
        $provider->setKernelContext($root, $config, []);
        $provider->setKernelServices(new class ($this->application->database()) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });
        $provider->register();

        $indexer = $provider->resolve(SearchIndexerInterface::class);
        self::assertInstanceOf(SearchIndexerInterface::class, $indexer);

        return $indexer;
    }

    /** @return list<string> */
    private function projectionTables(DatabaseInterface $database): array
    {
        $rows = iterator_to_array($database->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('search_index', 'search_metadata') ORDER BY name",
        ));

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }
}
