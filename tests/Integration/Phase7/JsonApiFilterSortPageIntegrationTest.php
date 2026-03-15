<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Filtering, sorting, and pagination integration tests with real storage.
 *
 * Exercises: waaseyaa/api (JsonApiController, QueryParser, QueryApplier,
 * PaginationLinks) with waaseyaa/entity (EntityTypeManager) using
 * in-memory storage with 10+ entities for meaningful query tests.
 */
#[CoversNothing]
final class JsonApiFilterSortPageIntegrationTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('node');

        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ],
        ));

        $serializer = new ResourceSerializer($entityTypeManager);
        $this->controller = new JsonApiController($entityTypeManager, $serializer);

        // Seed 12 nodes with varying attributes.
        $this->seedNodes();
    }

    private function seedNodes(): void
    {
        $nodes = [
            ['title' => 'Alpha Post', 'status' => 1, 'type' => 'article'],
            ['title' => 'Beta Post', 'status' => 1, 'type' => 'article'],
            ['title' => 'Charlie Post', 'status' => 0, 'type' => 'article'],
            ['title' => 'Delta Post', 'status' => 1, 'type' => 'page'],
            ['title' => 'Echo Post', 'status' => 0, 'type' => 'page'],
            ['title' => 'Foxtrot Post', 'status' => 1, 'type' => 'article'],
            ['title' => 'Golf Post', 'status' => 1, 'type' => 'page'],
            ['title' => 'Hotel Post', 'status' => 0, 'type' => 'article'],
            ['title' => 'India Post', 'status' => 1, 'type' => 'article'],
            ['title' => 'Juliet Post', 'status' => 1, 'type' => 'page'],
            ['title' => 'Kilo Post', 'status' => 0, 'type' => 'page'],
            ['title' => 'Lima Post', 'status' => 1, 'type' => 'article'],
        ];

        foreach ($nodes as $values) {
            $entity = $this->storage->create($values);
            $this->storage->save($entity);
        }
    }

    #[Test]
    public function filterByStatusReturnsOnlyPublished(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => ['status' => 1],
        ]);
        $array = $doc->toArray();

        $this->assertSame(8, $array['meta']['total']);
        $this->assertCount(8, $array['data']);

        foreach ($array['data'] as $resource) {
            $this->assertSame(1, $resource['attributes']['status']);
        }
    }

    #[Test]
    public function filterByStatusReturnsOnlyUnpublished(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => ['status' => 0],
        ]);
        $array = $doc->toArray();

        $this->assertSame(4, $array['meta']['total']);
        $this->assertCount(4, $array['data']);

        foreach ($array['data'] as $resource) {
            $this->assertSame(0, $resource['attributes']['status']);
        }
    }

    #[Test]
    public function filterByBundleReturnsCorrectType(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => ['type' => 'article'],
        ]);
        $array = $doc->toArray();

        $this->assertSame(7, $array['meta']['total']);
        foreach ($array['data'] as $resource) {
            $this->assertSame('article', $resource['attributes']['type']);
        }
    }

    #[Test]
    public function sortByTitleAscending(): void
    {
        $doc = $this->controller->index('node', [
            'sort' => 'title',
        ]);
        $array = $doc->toArray();

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $sorted = $titles;
        sort($sorted);

        $this->assertSame($sorted, $titles);
        $this->assertSame('Alpha Post', $titles[0]);
        $this->assertSame('Lima Post', $titles[count($titles) - 1]);
    }

    #[Test]
    public function sortByTitleDescending(): void
    {
        $doc = $this->controller->index('node', [
            'sort' => '-title',
        ]);
        $array = $doc->toArray();

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);

        $this->assertSame('Lima Post', $titles[0]);
        $this->assertSame('Alpha Post', $titles[count($titles) - 1]);
    }

    #[Test]
    public function paginationFirstPage(): void
    {
        $doc = $this->controller->index('node', [
            'page' => ['offset' => '0', 'limit' => '3'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        $this->assertSame(12, $array['meta']['total']);
        $this->assertSame(0, $array['meta']['offset']);
        $this->assertSame(3, $array['meta']['limit']);

        // First page should have self and next links, but no prev.
        $this->assertArrayHasKey('self', $array['links']);
        $this->assertArrayHasKey('next', $array['links']);
        $this->assertArrayNotHasKey('prev', $array['links']);
    }

    #[Test]
    public function paginationSecondPage(): void
    {
        $doc = $this->controller->index('node', [
            'page' => ['offset' => '3', 'limit' => '3'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        $this->assertSame(12, $array['meta']['total']);
        $this->assertSame(3, $array['meta']['offset']);

        // Middle page should have self, prev, and next links.
        $this->assertArrayHasKey('self', $array['links']);
        $this->assertArrayHasKey('prev', $array['links']);
        $this->assertArrayHasKey('next', $array['links']);
    }

    #[Test]
    public function paginationLastPage(): void
    {
        $doc = $this->controller->index('node', [
            'page' => ['offset' => '9', 'limit' => '3'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        $this->assertSame(12, $array['meta']['total']);

        // Last page should have self and prev links, but no next.
        $this->assertArrayHasKey('self', $array['links']);
        $this->assertArrayHasKey('prev', $array['links']);
        $this->assertArrayNotHasKey('next', $array['links']);
    }

    #[Test]
    public function paginationBeyondLastPage(): void
    {
        $doc = $this->controller->index('node', [
            'page' => ['offset' => '100', 'limit' => '3'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(0, $array['data']);
        $this->assertSame(12, $array['meta']['total']);
    }

    #[Test]
    public function sparseFieldsetsReturnOnlyRequestedFields(): void
    {
        $doc = $this->controller->index('node', [
            'fields' => ['node' => 'title,status'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(12, $array['data']);

        foreach ($array['data'] as $resource) {
            $this->assertArrayHasKey('title', $resource['attributes']);
            $this->assertArrayHasKey('status', $resource['attributes']);
            $this->assertArrayNotHasKey('type', $resource['attributes']);
        }
    }

    #[Test]
    public function sparseFieldsetsSingleField(): void
    {
        $doc = $this->controller->index('node', [
            'fields' => ['node' => 'title'],
        ]);
        $array = $doc->toArray();

        foreach ($array['data'] as $resource) {
            $this->assertArrayHasKey('title', $resource['attributes']);
            $this->assertCount(1, $resource['attributes']);
        }
    }

    #[Test]
    public function combinedFilterAndSort(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => ['status' => 1],
            'sort' => 'title',
        ]);
        $array = $doc->toArray();

        $this->assertSame(8, $array['meta']['total']);

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $sorted = $titles;
        sort($sorted);
        $this->assertSame($sorted, $titles);

        // All should be published.
        foreach ($array['data'] as $resource) {
            $this->assertSame(1, $resource['attributes']['status']);
        }
    }

    #[Test]
    public function combinedFilterSortAndPagination(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => ['status' => 1],
            'sort' => 'title',
            'page' => ['offset' => '0', 'limit' => '3'],
        ]);
        $array = $doc->toArray();

        // 8 published nodes total, first page of 3.
        $this->assertCount(3, $array['data']);
        $this->assertSame(8, $array['meta']['total']);
        $this->assertSame(0, $array['meta']['offset']);
        $this->assertSame(3, $array['meta']['limit']);

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $this->assertSame('Alpha Post', $titles[0]);
        $this->assertSame('Beta Post', $titles[1]);
        $this->assertSame('Delta Post', $titles[2]);
    }

    #[Test]
    public function combinedFilterSortPaginationSecondPage(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => ['status' => 1],
            'sort' => 'title',
            'page' => ['offset' => '3', 'limit' => '3'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        $this->assertSame(8, $array['meta']['total']);

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $this->assertSame('Foxtrot Post', $titles[0]);
        $this->assertSame('Golf Post', $titles[1]);
        $this->assertSame('India Post', $titles[2]);
    }

    #[Test]
    public function combinedFilterSortPaginationAndFieldsets(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => ['type' => 'article'],
            'sort' => '-title',
            'page' => ['offset' => '0', 'limit' => '3'],
            'fields' => ['node' => 'title,status'],
        ]);
        $array = $doc->toArray();

        // 7 articles total.
        $this->assertSame(7, $array['meta']['total']);
        $this->assertCount(3, $array['data']);

        foreach ($array['data'] as $resource) {
            $this->assertArrayHasKey('title', $resource['attributes']);
            $this->assertArrayHasKey('status', $resource['attributes']);
            $this->assertArrayNotHasKey('type', $resource['attributes']);
        }

        // Descending sort, first 3.
        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $this->assertSame('Lima Post', $titles[0]);
        $this->assertSame('India Post', $titles[1]);
        $this->assertSame('Hotel Post', $titles[2]);
    }

    #[Test]
    public function filterWithMultipleCriteria(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => [
                'status' => 1,
                'type' => 'article',
            ],
        ]);
        $array = $doc->toArray();

        // Published articles: Alpha, Beta, Foxtrot, India, Lima = 5.
        $this->assertSame(5, $array['meta']['total']);
        $this->assertCount(5, $array['data']);

        foreach ($array['data'] as $resource) {
            $this->assertSame(1, $resource['attributes']['status']);
            $this->assertSame('article', $resource['attributes']['type']);
        }
    }

    #[Test]
    public function paginationLinksContainCorrectPaths(): void
    {
        $doc = $this->controller->index('node', [
            'page' => ['offset' => '3', 'limit' => '3'],
        ]);
        $array = $doc->toArray();

        // Self link should reference the current page parameters.
        $this->assertStringContainsString('/api/node', $array['links']['self']);

        // First link should exist.
        $this->assertArrayHasKey('first', $array['links']);
        $this->assertStringContainsString('/api/node', $array['links']['first']);
    }

    #[Test]
    public function defaultPaginationReturnsAllWithinLimit(): void
    {
        $doc = $this->controller->index('node');
        $array = $doc->toArray();

        // Default limit is 50, so all 12 should be returned.
        $this->assertCount(12, $array['data']);
        $this->assertSame(12, $array['meta']['total']);
        $this->assertSame(0, $array['meta']['offset']);
        $this->assertSame(50, $array['meta']['limit']);
    }

    #[Test]
    public function filterWithOperatorNotEqual(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => [
                'type' => [
                    'operator' => '!=',
                    'value' => 'page',
                ],
            ],
        ]);
        $array = $doc->toArray();

        // All articles (not pages).
        $this->assertSame(7, $array['meta']['total']);
        foreach ($array['data'] as $resource) {
            $this->assertNotSame('page', $resource['attributes']['type']);
        }
    }

    #[Test]
    public function filterWithContainsOperator(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => [
                'title' => [
                    'operator' => 'CONTAINS',
                    'value' => 'Post',
                ],
            ],
        ]);
        $array = $doc->toArray();

        // All 12 titles contain "Post".
        $this->assertSame(12, $array['meta']['total']);
    }

    #[Test]
    public function filterWithStartsWithOperator(): void
    {
        $doc = $this->controller->index('node', [
            'filter' => [
                'title' => [
                    'operator' => 'STARTS_WITH',
                    'value' => 'Alpha',
                ],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(1, $array['meta']['total']);
        $this->assertCount(1, $array['data']);
        $this->assertSame('Alpha Post', $array['data'][0]['attributes']['title']);
    }
}
