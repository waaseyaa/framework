<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Query;

use Aurora\Api\JsonApiController;
use Aurora\Api\ResourceSerializer;
use Aurora\Api\Tests\Fixtures\InMemoryEntityStorage;
use Aurora\Api\Tests\Fixtures\TestEntity;
use Aurora\Entity\EntityType;
use Aurora\Entity\EntityTypeManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(JsonApiController::class)]
final class JsonApiControllerFilterTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
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

        // Seed test data: 5 articles with different statuses and titles.
        $this->createAndSaveEntity(['title' => 'Alpha Article', 'status' => 1, 'category' => 'news']);
        $this->createAndSaveEntity(['title' => 'Beta Article', 'status' => 1, 'category' => 'blog']);
        $this->createAndSaveEntity(['title' => 'Charlie Article', 'status' => 0, 'category' => 'news']);
        $this->createAndSaveEntity(['title' => 'Delta Article', 'status' => 1, 'category' => 'blog']);
        $this->createAndSaveEntity(['title' => 'Echo Article', 'status' => 0, 'category' => 'tutorial']);
    }

    // --- Filtering ---

    #[Test]
    public function indexFiltersEntitiesBySimpleEquality(): void
    {
        $doc = $this->controller->index('article', [
            'filter' => ['status' => 1],
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        foreach ($array['data'] as $resource) {
            $this->assertSame(1, $resource['attributes']['status']);
        }
    }

    #[Test]
    public function indexFiltersEntitiesByOperator(): void
    {
        $doc = $this->controller->index('article', [
            'filter' => [
                'category' => [
                    'operator' => '!=',
                    'value' => 'news',
                ],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        foreach ($array['data'] as $resource) {
            $this->assertNotSame('news', $resource['attributes']['category']);
        }
    }

    #[Test]
    public function indexFiltersWithContainsOperator(): void
    {
        $doc = $this->controller->index('article', [
            'filter' => [
                'title' => [
                    'operator' => 'CONTAINS',
                    'value' => 'Article',
                ],
            ],
        ]);
        $array = $doc->toArray();

        // All 5 articles contain "Article" in their title.
        $this->assertCount(5, $array['data']);
    }

    #[Test]
    public function indexFiltersWithStartsWithOperator(): void
    {
        $doc = $this->controller->index('article', [
            'filter' => [
                'title' => [
                    'operator' => 'STARTS_WITH',
                    'value' => 'Alpha',
                ],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        $this->assertSame('Alpha Article', $array['data'][0]['attributes']['title']);
    }

    // --- Sorting ---

    #[Test]
    public function indexSortsEntitiesAscending(): void
    {
        $doc = $this->controller->index('article', [
            'sort' => 'title',
        ]);
        $array = $doc->toArray();

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $this->assertSame('Alpha Article', $titles[0]);
        $this->assertSame('Beta Article', $titles[1]);
        $this->assertSame('Charlie Article', $titles[2]);
        $this->assertSame('Delta Article', $titles[3]);
        $this->assertSame('Echo Article', $titles[4]);
    }

    #[Test]
    public function indexSortsEntitiesDescending(): void
    {
        $doc = $this->controller->index('article', [
            'sort' => '-title',
        ]);
        $array = $doc->toArray();

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $this->assertSame('Echo Article', $titles[0]);
        $this->assertSame('Delta Article', $titles[1]);
        $this->assertSame('Charlie Article', $titles[2]);
        $this->assertSame('Beta Article', $titles[3]);
        $this->assertSame('Alpha Article', $titles[4]);
    }

    // --- Pagination ---

    #[Test]
    public function indexPaginatesResults(): void
    {
        $doc = $this->controller->index('article', [
            'page' => ['offset' => '0', 'limit' => '2'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(2, $array['data']);
        $this->assertSame(5, $array['meta']['total']);
        $this->assertSame(0, $array['meta']['offset']);
        $this->assertSame(2, $array['meta']['limit']);
    }

    #[Test]
    public function indexPaginatesWithOffset(): void
    {
        $doc = $this->controller->index('article', [
            'page' => ['offset' => '2', 'limit' => '2'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(2, $array['data']);
        $this->assertSame(5, $array['meta']['total']);
        $this->assertSame(2, $array['meta']['offset']);
    }

    #[Test]
    public function indexPaginatesLastPage(): void
    {
        $doc = $this->controller->index('article', [
            'page' => ['offset' => '4', 'limit' => '2'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        $this->assertSame(5, $array['meta']['total']);
    }

    #[Test]
    public function indexIncludesPaginationLinks(): void
    {
        $doc = $this->controller->index('article', [
            'page' => ['offset' => '2', 'limit' => '2'],
        ]);
        $array = $doc->toArray();

        $this->assertArrayHasKey('self', $array['links']);
        $this->assertArrayHasKey('first', $array['links']);
        $this->assertArrayHasKey('prev', $array['links']);
        $this->assertArrayHasKey('next', $array['links']);
    }

    #[Test]
    public function indexFirstPageHasNoPrevLink(): void
    {
        $doc = $this->controller->index('article', [
            'page' => ['offset' => '0', 'limit' => '2'],
        ]);
        $array = $doc->toArray();

        $this->assertArrayNotHasKey('prev', $array['links']);
        $this->assertArrayHasKey('next', $array['links']);
    }

    #[Test]
    public function indexLastPageHasNoNextLink(): void
    {
        $doc = $this->controller->index('article', [
            'page' => ['offset' => '4', 'limit' => '2'],
        ]);
        $array = $doc->toArray();

        $this->assertArrayNotHasKey('next', $array['links']);
        $this->assertArrayHasKey('prev', $array['links']);
    }

    #[Test]
    public function indexDefaultPaginationWithEmptyQuery(): void
    {
        $doc = $this->controller->index('article');
        $array = $doc->toArray();

        // All 5 entities fit within default limit of 50.
        $this->assertCount(5, $array['data']);
        $this->assertSame(5, $array['meta']['total']);
        $this->assertSame(0, $array['meta']['offset']);
        $this->assertSame(50, $array['meta']['limit']);
    }

    // --- Sparse Fieldsets ---

    #[Test]
    public function indexAppliesSparseFieldsets(): void
    {
        $doc = $this->controller->index('article', [
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        foreach ($array['data'] as $resource) {
            $this->assertArrayHasKey('title', $resource['attributes']);
            $this->assertArrayNotHasKey('status', $resource['attributes']);
            $this->assertArrayNotHasKey('category', $resource['attributes']);
        }
    }

    #[Test]
    public function indexSparseFieldsetsWithMultipleFields(): void
    {
        $doc = $this->controller->index('article', [
            'fields' => ['article' => 'title,status'],
        ]);
        $array = $doc->toArray();

        foreach ($array['data'] as $resource) {
            $this->assertArrayHasKey('title', $resource['attributes']);
            $this->assertArrayHasKey('status', $resource['attributes']);
            $this->assertArrayNotHasKey('category', $resource['attributes']);
        }
    }

    // --- Combined parameters ---

    #[Test]
    public function indexCombinesFilterSortAndPagination(): void
    {
        $doc = $this->controller->index('article', [
            'filter' => ['status' => 1],
            'sort' => '-title',
            'page' => ['offset' => '0', 'limit' => '2'],
        ]);
        $array = $doc->toArray();

        // 3 articles with status=1, sorted DESC by title, limited to 2.
        $this->assertCount(2, $array['data']);
        $this->assertSame(3, $array['meta']['total']);

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $this->assertSame('Delta Article', $titles[0]);
        $this->assertSame('Beta Article', $titles[1]);
    }

    #[Test]
    public function indexCombinesFilterSortPaginationAndFieldsets(): void
    {
        $doc = $this->controller->index('article', [
            'filter' => ['status' => 1],
            'sort' => 'title',
            'page' => ['offset' => '0', 'limit' => '10'],
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        foreach ($array['data'] as $resource) {
            $this->assertArrayHasKey('title', $resource['attributes']);
            $this->assertArrayNotHasKey('status', $resource['attributes']);
        }

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $this->assertSame(['Alpha Article', 'Beta Article', 'Delta Article'], $titles);
    }

    // --- Backward compatibility ---

    #[Test]
    public function indexWorksWithEmptyQueryArray(): void
    {
        $doc = $this->controller->index('article', []);
        $array = $doc->toArray();

        $this->assertCount(5, $array['data']);
        $this->assertArrayHasKey('links', $array);
        $this->assertArrayHasKey('meta', $array);
    }

    #[Test]
    public function indexWorksWithNoQueryArgument(): void
    {
        $doc = $this->controller->index('article');
        $array = $doc->toArray();

        $this->assertCount(5, $array['data']);
    }

    // --- Helpers ---

    private function createAndSaveEntity(array $values): TestEntity
    {
        /** @var TestEntity $entity */
        $entity = $this->storage->create($values);
        $this->storage->save($entity);

        return $entity;
    }
}
