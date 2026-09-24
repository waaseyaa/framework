<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Search\EventSubscriber\SearchIndexSubscriber;
use Waaseyaa\Search\Fts5\Fts5SearchContentCatalogue;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver;
use Waaseyaa\Search\Tests\Support\SearchTestPrincipal;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * FW-SEARCH-PERSIST-01 / #3146: the search projection's serving paths
 * (lifecycle save and delete, full reindex, search, content catalogue) perform
 * no DDL on a real SQLite file, so the recorded manifest still describes the
 * live schema and the next coordinated transition succeeds.
 */
#[CoversNothing]
final class SearchServingPathSchemaAuthorityTest extends TestCase
{
    private const array PROJECTION_OBJECTS = [
        'search_index',
        'search_metadata',
        'idx_search_meta_entity_type',
        'idx_search_meta_content_type',
        'idx_search_meta_source',
    ];

    private TemporarySqliteDatabase $temporary;
    private DBALDatabase $database;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->temporary = new TemporarySqliteDatabase();
        $database = $this->temporary->database();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $this->database = $database;
        $this->connection = $database->getConnection();
        new EntitySchemaSyncRunner($database)->run([self::entityType('probe_first')]);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->temporary->remove();
    }

    #[Test]
    public function withTheMigrationAppliedTheServingPathsKeepTheManifestValid(): void
    {
        RuntimeSchemaMigrations::search($this->database);
        $recorded = $this->manifestFingerprint();

        $hits = $this->exerciseServingPaths();

        self::assertSame(['note:1'], $hits, 'the save indexed one document, the delete removed the other, and the reindex restored both');
        $this->assertManifestUnchangedAndNextTransitionSucceeds($recorded);
    }

    #[Test]
    public function withoutTheMigrationTheServingPathsCreateNothing(): void
    {
        $recorded = $this->manifestFingerprint();

        $hits = $this->exerciseServingPaths();

        $this->assertManifestUnchangedAndNextTransitionSucceeds($recorded);
        self::assertSame([], $this->projectionObjects(), 'no serving path created a search projection object');
        self::assertSame([], $hits);
    }

    /** @return list<string> the document ids a search for "note" returns */
    private function exerciseServingPaths(): array
    {
        $indexer = new Fts5SearchIndexer($this->database);
        $subscriber = new SearchIndexSubscriber($indexer);
        $subscriber->onPostSave(new EntityEvent(new SearchServingPathProbeEntity(1, 'first note')));
        $subscriber->onPostSave(new EntityEvent(new SearchServingPathProbeEntity(2, 'second note')));
        // The FETDER trigger: an entity delete through the lifecycle subscriber.
        $subscriber->onPostDelete(new EntityEvent(new SearchServingPathProbeEntity(2, 'second note')));

        // search:reindex clears the index, then rebuilds it in batches.
        $indexer->removeAll();
        $indexer->reindexBatch([new SearchServingPathProbeEntity(1, 'first note')]);

        $resolver = new IndexedSearchCandidateResolver($this->database);
        $principal = SearchTestPrincipal::create();
        new Fts5SearchContentCatalogue($this->database, $resolver)->list($principal);
        new Fts5SearchContentCatalogue($this->database, $resolver)->readByPublicPath('/note/1', $principal);
        $result = new Fts5SearchProvider($this->database, $indexer, $resolver)->search(new SearchRequest('note'), $principal);

        return array_map(static fn($hit): string => $hit->id, $result->hits);
    }

    private function assertManifestUnchangedAndNextTransitionSucceeds(?string $recorded): void
    {
        self::assertNotNull($recorded);
        $repository = new MigrationRepository($this->connection);
        self::assertSame($recorded, $repository->currentLogicalSchemaFingerprint(), 'strict verification: no schema drift');

        new EntitySchemaSyncRunner($this->database)->run([self::entityType('probe_first'), self::entityType('probe_second')]);
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['probe_second']), 'the next coordinated transition succeeded');
    }

    /** @return list<string> */
    private function projectionObjects(): array
    {
        return array_values(array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT name FROM sqlite_master WHERE name = 'search_index' OR name LIKE 'search\\_%' ESCAPE '\\' OR name LIKE 'idx\\_search\\_meta\\_%' ESCAPE '\\' ORDER BY name",
        )));
    }

    private function manifestFingerprint(): ?string
    {
        return new MigrationRepository($this->connection)->schemaAuthorityManifest()?->schemaFingerprint;
    }

    private static function entityType(string $id): EntityType
    {
        return new EntityType(
            id: $id,
            label: $id,
            class: ContentEntityBase::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
    }
}

final readonly class SearchServingPathProbeEntity implements EntityInterface, SearchIndexableInterface
{
    public function __construct(private int $id, private string $title) {}

    public function getSearchDocumentId(): string
    {
        return 'note:' . $this->id;
    }

    public function toSearchDocument(): array
    {
        return ['title' => $this->title, 'body' => $this->title . ' body'];
    }

    public function toSearchMetadata(): array
    {
        return [
            'entity_type' => 'note',
            'content_type' => 'note',
            'url' => '/note/' . $this->id,
            'created_at' => '2026-09-24T00:00:0' . $this->id . 'Z',
        ];
    }

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return '';
    }

    public function label(): string
    {
        return $this->title;
    }

    public function getEntityTypeId(): string
    {
        return 'note';
    }

    public function bundle(): string
    {
        return 'default';
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed
    {
        return null;
    }

    public function set(string $name, mixed $value): static
    {
        throw new \LogicException('Readonly');
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title];
    }

    public function language(): string
    {
        return 'en';
    }
}
