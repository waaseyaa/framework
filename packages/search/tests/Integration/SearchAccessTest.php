<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Search\Access\EntitySearchAccessChecker;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

/**
 * The FTS5 index is populated automatically on entity save, so it holds rows a
 * caller may not be allowed to view (e.g. an unpublished node). The read path
 * must enforce per-document `view` access, not return every matching row.
 */
#[CoversClass(Fts5SearchProvider::class)]
#[CoversClass(EntitySearchAccessChecker::class)]
final class SearchAccessTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexer->ensureSchema();

        // Two entity-backed documents both matching the query: a viewable one
        // ('node:1', bundle 'article') and a forbidden one ('node:2', bundle
        // 'secret').
        $this->indexDoc('node:1', 'node', 'Public Report');
        $this->indexDoc('node:2', 'node', 'Secret Report');
        // A non-entity document (crawled markdown / spec) the app indexed.
        $this->indexDoc('spec:overview', 'document', 'Overview Report');
    }

    #[Test]
    public function forbidden_entity_documents_are_excluded_from_results(): void
    {
        $provider = new Fts5SearchProvider(
            $this->database,
            $this->indexer,
            accessChecker: $this->accessChecker(),
        );

        $hits = $provider->search(new SearchRequest('Report'))->hits;
        $ids = array_map(static fn ($hit): string => $hit->id, $hits);

        // The forbidden node is gone; the viewable node and the non-entity
        // document (no entity policy applies) remain.
        self::assertContains('node:1', $ids);
        self::assertContains('spec:overview', $ids);
        self::assertNotContains('node:2', $ids, 'Forbidden node leaked into search results');
    }

    #[Test]
    public function without_a_checker_the_index_leaks_every_match(): void
    {
        // Demonstrates the pre-fix behaviour: with no access checker wired the
        // provider returns every matching row, forbidden or not.
        $provider = new Fts5SearchProvider($this->database, $this->indexer);

        $ids = array_map(
            static fn ($hit): string => $hit->id,
            $provider->search(new SearchRequest('Report'))->hits,
        );

        self::assertContains('node:2', $ids);
    }

    private function accessChecker(): EntitySearchAccessChecker
    {
        $article = $this->entity('node', 'article');
        $secret = $this->entity('node', 'secret');

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturnMap([
            ['1', $article],
            ['2', $secret],
        ]);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturnCallback(static fn (string $type): bool => $type === 'node');
        $etm->method('getStorage')->with('node')->willReturn($storage);

        // Policy: 'secret'-bundle nodes are forbidden for 'view'; everything
        // else is allowed.
        $policy = $this->createMock(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturnCallback(static fn (string $type): bool => $type === 'node');
        $policy->method('access')->willReturnCallback(
            static fn (EntityInterface $entity): AccessResult => $entity->bundle() === 'secret'
                ? AccessResult::forbidden('secret content')
                : AccessResult::allowed(),
        );

        $context = new RequestAccountContext();
        $context->set($this->createMock(AccountInterface::class));

        return new EntitySearchAccessChecker(
            $etm,
            new EntityAccessHandler([$policy]),
            $context,
        );
    }

    private function entity(string $type, string $bundle): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($type);
        $entity->method('bundle')->willReturn($bundle);

        return $entity;
    }

    private function indexDoc(string $id, string $entityType, string $title): void
    {
        $this->indexer->index(new class ($id, $entityType, $title) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly string $entityType,
                private readonly string $title,
            ) {}

            public function getSearchDocumentId(): string
            {
                return $this->id;
            }

            /** @return array<string, string> */
            public function toSearchDocument(): array
            {
                return ['title' => $this->title, 'body' => 'Report body content'];
            }

            /** @return array<string, mixed> */
            public function toSearchMetadata(): array
            {
                return [
                    'entity_type' => $this->entityType,
                    'content_type' => '',
                    'source_name' => '',
                    'quality_score' => 0,
                    'topics' => [],
                    'url' => '',
                    'og_image' => '',
                    'created_at' => '2026-06-22T00:00:00Z',
                ];
            }
        });
    }
}
