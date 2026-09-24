<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\Fts5\Fts5SearchSchema;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

/**
 * FW-SEARCH-PERSIST-01: the indexer's serving paths never create the search
 * projection. On the application database the waaseyaa/search migration owns
 * it; a dedicated `search.database` file is provisioned only by removeAll(),
 * the `search:reindex` rebuild. Construction runs no DDL either (D-35).
 */
#[CoversClass(Fts5SearchIndexer::class)]
final class Fts5SearchIndexerSchemaBoundaryTest extends TestCase
{
    #[Test]
    public function constructing_the_indexer_does_not_create_the_schema(): void
    {
        $database = DBALDatabase::createSqlite();

        new Fts5SearchIndexer($database);

        $this->assertSame([], $this->searchTables($database));
    }

    #[Test]
    public function writes_without_the_projection_create_nothing_and_log_a_warning(): void
    {
        $database = DBALDatabase::createSqlite();
        $logger = new SchemaBoundaryRecordingLogger();
        $indexer = new Fts5SearchIndexer($database, $logger);

        $indexer->index($this->indexable('node:1'));
        $indexer->remove('node:1');
        $this->assertSame(0, $indexer->reindexBatch([$this->indexable('node:2')]));

        $this->assertSame([], $this->searchTables($database));
        $this->assertCount(3, $logger->warnings);
        $this->assertStringContainsString('Run `migrate`', $logger->warnings[0]);
    }

    #[Test]
    public function remove_all_on_the_application_database_refuses_a_missing_projection(): void
    {
        $database = DBALDatabase::createSqlite();

        try {
            new Fts5SearchIndexer($database)->removeAll();
            self::fail('removeAll() must refuse a missing projection on the application database.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('[SEARCH-DB002]', $e->getMessage());
        }

        $this->assertSame([], $this->searchTables($database));
    }

    #[Test]
    public function a_migration_applied_later_is_picked_up(): void
    {
        $database = DBALDatabase::createSqlite();
        $indexer = new Fts5SearchIndexer($database);
        $indexer->index($this->indexable('node:1'));

        Fts5SearchSchema::install($database->getConnection());
        $indexer->index($this->indexable('node:2'));

        $this->assertSame(['node:2'], $this->documentIds($database));
    }

    #[Test]
    public function a_dedicated_projection_file_is_provisioned_only_by_remove_all(): void
    {
        $database = DBALDatabase::createSqlite();
        $logger = new SchemaBoundaryRecordingLogger();
        $indexer = new Fts5SearchIndexer($database, $logger, ownsProjectionFile: true);

        $indexer->index($this->indexable('node:1'));
        $this->assertSame([], $this->searchTables($database), 'a serving write does not provision the dedicated file');
        $this->assertStringContainsString('search:reindex', $logger->warnings[0]);

        $indexer->removeAll();
        $this->assertSame(['search_index', 'search_metadata'], $this->searchTables($database));

        $indexer->index($this->indexable('node:1'));
        $this->assertSame(['node:1'], $this->documentIds($database));
    }

    #[Test]
    public function an_incompatible_dedicated_file_is_refused_with_file_recovery_and_left_unchanged(): void
    {
        $database = DBALDatabase::createSqlite();
        // The state an interrupted pre-transaction tokenizer rebuild could leave.
        $database->query('CREATE VIRTUAL TABLE search_index_retired_porter USING fts5(document_id UNINDEXED, title, body)');
        $before = $this->schema($database);

        try {
            new Fts5SearchIndexer($database, ownsProjectionFile: true)->removeAll();
            self::fail('An incompatible dedicated projection file must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('[SEARCH-DB001]', $e->getMessage());
            $this->assertStringContainsString('move that file aside and run `search:reindex`', $e->getMessage());
            $this->assertStringNotContainsString('so the migration can', $e->getMessage(), 'no migration runs on a dedicated file');
        }

        $this->assertSame($before, $this->schema($database));
    }

    #[Test]
    public function a_failed_dedicated_rebuild_rolls_back_its_provisioning(): void
    {
        $database = DBALDatabase::createSqlite();
        $connection = $database->getConnection();
        $connection->executeStatement("CREATE VIRTUAL TABLE search_index USING fts5(document_id UNINDEXED, title, body, tokenize='porter unicode61')");
        $connection->executeStatement(self::metadataDdl());
        $connection->executeStatement("INSERT INTO search_index (document_id, title, body) VALUES ('node:1', 'Running', 'runs')");
        $connection->executeStatement("INSERT INTO search_metadata (document_id, entity_type, created_at, schema_version) VALUES ('node:1', 'node', '2026-09-24', '2')");
        // Fail the row deletes that follow the tokenizer rebuild in removeAll().
        $connection->executeStatement("CREATE TRIGGER fail_metadata_delete BEFORE DELETE ON search_metadata BEGIN SELECT RAISE(ABORT, 'delete failed'); END");
        $before = $this->schema($database);

        try {
            new Fts5SearchIndexer($database, ownsProjectionFile: true)->removeAll();
            self::fail('The failing delete must surface.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('delete failed', $e->getMessage());
        }

        $this->assertSame($before, $this->schema($database), 'the Porter index is back and no rebuild leftover remains');
        $this->assertSame('node:1', $connection->fetchOne("SELECT document_id FROM search_index WHERE search_index MATCH 'run'"));
        $this->assertSame(['node:1'], $this->documentIds($database));
    }

    #[Test]
    public function searching_a_missing_projection_returns_empty_not_an_error(): void
    {
        $database = DBALDatabase::createSqlite();
        $provider = new Fts5SearchProvider(
            $database,
            new Fts5SearchIndexer($database),
            new \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver($database),
        );

        $result = $provider->search(new SearchRequest('anything'), \Waaseyaa\Search\Tests\Support\SearchTestPrincipal::create());

        $this->assertSame(0, $result->totalHits);
        $this->assertSame([], $result->hits);
        $this->assertSame([], $this->searchTables($database), 'read path must not create the schema');
    }

    /**
     * @return list<string>
     */
    private function searchTables(DBALDatabase $database): array
    {
        $rows = iterator_to_array($database->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('search_index', 'search_metadata') ORDER BY name",
        ));

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function schema(DBALDatabase $database): array
    {
        return $database->getConnection()->fetchAllAssociative('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY name');
    }

    private static function metadataDdl(): string
    {
        return <<<'SQL'
            CREATE TABLE search_metadata (
                document_id TEXT PRIMARY KEY,
                entity_type TEXT NOT NULL,
                content_type TEXT NOT NULL DEFAULT '',
                source_name TEXT NOT NULL DEFAULT '',
                quality_score INTEGER NOT NULL DEFAULT 0,
                topics TEXT NOT NULL DEFAULT '[]',
                url TEXT NOT NULL DEFAULT '',
                og_image TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                schema_version TEXT NOT NULL
            )
            SQL;
    }

    /** @return list<string> */
    private function documentIds(DBALDatabase $database): array
    {
        $rows = iterator_to_array($database->query('SELECT document_id FROM search_metadata ORDER BY document_id'));

        return array_map(static fn(array $row): string => (string) $row['document_id'], $rows);
    }

    private function indexable(string $documentId): SearchIndexableInterface
    {
        return new class ($documentId) implements SearchIndexableInterface {
            public function __construct(private readonly string $documentId) {}

            public function getSearchDocumentId(): string
            {
                return $this->documentId;
            }

            public function toSearchDocument(): array
            {
                return ['title' => 'Title', 'body' => 'Body'];
            }

            public function toSearchMetadata(): array
            {
                return ['entity_type' => 'node'];
            }
        };
    }
}

final class SchemaBoundaryRecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<string> */
    public array $warnings = [];

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::WARNING) {
            $this->warnings[] = (string) $message;
        }
    }
}
