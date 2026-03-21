<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\SearchIndexableInterface;

#[CoversClass(Fts5SearchIndexer::class)]
final class Fts5SearchIndexerTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexer->ensureSchema();
    }

    #[Test]
    public function it_creates_fts5_and_metadata_tables(): void
    {
        // Tables created in setUp — verify they exist by querying
        $rows = iterator_to_array($this->database->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('search_index', 'search_metadata') ORDER BY name"));

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function it_indexes_a_document(): void
    {
        $item = $this->createIndexable('node:1', ['title' => 'Hello World', 'body' => 'Test content'], [
            'entity_type' => 'node',
            'content_type' => 'article',
            'source_name' => '',
            'quality_score' => 80,
            'topics' => ['php', 'testing'],
            'url' => '/node/1',
            'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $this->indexer->index($item);

        $rows = iterator_to_array($this->database->query("SELECT * FROM search_metadata WHERE document_id = 'node:1'"));
        $this->assertCount(1, $rows);
        $this->assertSame('node', $rows[0]['entity_type']);
    }

    #[Test]
    public function it_upserts_existing_document(): void
    {
        $item1 = $this->createIndexable('node:1', ['title' => 'Original', 'body' => 'First'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => '',
            'quality_score' => 50, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $item2 = $this->createIndexable('node:1', ['title' => 'Updated', 'body' => 'Second'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => '',
            'quality_score' => 90, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $this->indexer->index($item1);
        $this->indexer->index($item2);

        // Should have one row, not two
        $rows = iterator_to_array($this->database->query("SELECT * FROM search_metadata WHERE document_id = 'node:1'"));
        $this->assertCount(1, $rows);
        $this->assertSame(90, (int) $rows[0]['quality_score']);
    }

    #[Test]
    public function it_removes_a_document(): void
    {
        $item = $this->createIndexable('node:1', ['title' => 'Hello', 'body' => 'World'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexer->index($item);
        $this->indexer->remove('node:1');

        $rows = iterator_to_array($this->database->query("SELECT * FROM search_metadata WHERE document_id = 'node:1'"));
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function it_removes_all_documents(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->indexer->index($this->createIndexable("node:$i", ['title' => "T$i", 'body' => "B$i"], [
                'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
                'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
                'created_at' => '2026-03-20T00:00:00Z',
            ]));
        }

        $this->indexer->removeAll();

        $rows = iterator_to_array($this->database->query("SELECT COUNT(*) as cnt FROM search_metadata"));
        $this->assertSame(0, (int) $rows[0]['cnt']);
    }

    #[Test]
    public function it_returns_schema_version(): void
    {
        $version = $this->indexer->getSchemaVersion();

        $this->assertNotEmpty($version);
        $this->assertIsString($version);
    }

    private function createIndexable(string $id, array $document, array $metadata): SearchIndexableInterface
    {
        return new class ($id, $document, $metadata) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly array $document,
                private readonly array $metadata,
            ) {}

            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return $this->document; }
            public function toSearchMetadata(): array { return $this->metadata; }
        };
    }
}
