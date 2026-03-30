<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

#[CoversClass(JsonApiController::class)]
final class JsonApiControllerSparseFieldsetsTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;
    private ResourceSerializer $serializer;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
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

        $this->serializer = new ResourceSerializer($this->entityTypeManager);
        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            $this->serializer,
        );
    }

    // --- Sparse Fieldsets on Index (GET collection) ---

    #[Test]
    public function indexWithSparseFieldsetsIncludesOnlyRequestedFields(): void
    {
        $this->createAndSaveEntity(['title' => 'First', 'body' => 'Body 1', 'summary' => 'Sum 1']);

        $doc = $this->controller->index('article', [
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        $this->assertArrayHasKey('title', $array['data'][0]['attributes']);
        $this->assertArrayNotHasKey('body', $array['data'][0]['attributes']);
        $this->assertArrayNotHasKey('summary', $array['data'][0]['attributes']);
    }

    #[Test]
    public function indexWithSparseFieldsetsPreservesIdAndType(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Test', 'body' => 'Content']);

        $doc = $this->controller->index('article', [
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        // Per JSON:API spec, id and type are always present regardless of sparse fieldsets.
        $this->assertSame('article', $array['data'][0]['type']);
        $this->assertSame($entity->uuid(), $array['data'][0]['id']);
        // Only title in attributes.
        $this->assertSame(['title' => 'Test'], $array['data'][0]['attributes']);
    }

    #[Test]
    public function indexWithSparseFieldsetsMultipleFields(): void
    {
        $this->createAndSaveEntity(['title' => 'Post', 'body' => 'Content', 'summary' => 'Brief']);

        $doc = $this->controller->index('article', [
            'fields' => ['article' => 'title,body'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        $this->assertArrayHasKey('title', $array['data'][0]['attributes']);
        $this->assertArrayHasKey('body', $array['data'][0]['attributes']);
        $this->assertArrayNotHasKey('summary', $array['data'][0]['attributes']);
    }

    #[Test]
    public function indexWithSparseFieldsetsMultipleEntities(): void
    {
        $this->createAndSaveEntity(['title' => 'First', 'body' => 'Body 1']);
        $this->createAndSaveEntity(['title' => 'Second', 'body' => 'Body 2']);

        $doc = $this->controller->index('article', [
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(2, $array['data']);
        // Both resources should have only title in attributes.
        foreach ($array['data'] as $resource) {
            $this->assertArrayHasKey('title', $resource['attributes']);
            $this->assertArrayNotHasKey('body', $resource['attributes']);
        }
    }

    #[Test]
    public function indexWithSparseFieldsetsForDifferentTypeIsIgnored(): void
    {
        $this->createAndSaveEntity(['title' => 'Post', 'body' => 'Content']);

        // Request sparse fields for a type that doesn't match the collection type.
        $doc = $this->controller->index('article', [
            'fields' => ['page' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        // All attributes should be present since the fieldset is for 'page', not 'article'.
        $this->assertArrayHasKey('title', $array['data'][0]['attributes']);
        $this->assertArrayHasKey('body', $array['data'][0]['attributes']);
    }

    #[Test]
    public function indexWithSparseFieldsetsEmptyFieldList(): void
    {
        $this->createAndSaveEntity(['title' => 'Post', 'body' => 'Content']);

        // Empty fields string results in an empty allowed list — no attributes returned.
        $doc = $this->controller->index('article', [
            'fields' => ['article' => ''],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        // id and type still present.
        $this->assertSame('article', $array['data'][0]['type']);
        // When all attributes are filtered out, the key is omitted from the resource.
        $this->assertArrayNotHasKey('attributes', $array['data'][0]);
    }

    #[Test]
    public function indexWithSparseFieldsetsNonexistentFieldReturnsEmptyAttributes(): void
    {
        $this->createAndSaveEntity(['title' => 'Post', 'body' => 'Content']);

        $doc = $this->controller->index('article', [
            'fields' => ['article' => 'nonexistent_field'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        // When no requested fields match, attributes key is omitted from the resource.
        $this->assertArrayNotHasKey('attributes', $array['data'][0]);
    }

    #[Test]
    public function indexWithSparseFieldsetsPreservesRelationships(): void
    {
        $this->createAndSaveEntity(['title' => 'Post', 'body' => 'Content']);

        $doc = $this->controller->index('article', [
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        // Sparse fieldsets filter only applies to attributes, not relationships.
        // Verify attributes are filtered to only the requested field.
        $this->assertSame(['title' => 'Post'], $array['data'][0]['attributes']);
        // Relationships key is omitted when entity has no relationships (TestEntity has none).
        $this->assertArrayNotHasKey('relationships', $array['data'][0]);
    }

    #[Test]
    public function indexWithSparseFieldsetsCombinedWithFilter(): void
    {
        $this->createAndSaveEntity(['title' => 'Alpha', 'body' => 'First body']);
        $this->createAndSaveEntity(['title' => 'Beta', 'body' => 'Second body']);

        $doc = $this->controller->index('article', [
            'filter' => ['title' => 'Alpha'],
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        $this->assertSame('Alpha', $array['data'][0]['attributes']['title']);
        $this->assertArrayNotHasKey('body', $array['data'][0]['attributes']);
    }

    #[Test]
    public function indexWithSparseFieldsetsCombinedWithPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createAndSaveEntity(['title' => "Article {$i}", 'body' => "Body {$i}"]);
        }

        $doc = $this->controller->index('article', [
            'fields' => ['article' => 'title'],
            'page' => ['offset' => 0, 'limit' => 2],
        ]);
        $array = $doc->toArray();

        $this->assertCount(2, $array['data']);
        foreach ($array['data'] as $resource) {
            $this->assertArrayHasKey('title', $resource['attributes']);
            $this->assertArrayNotHasKey('body', $resource['attributes']);
        }
        $this->assertSame(5, $array['meta']['total']);
    }

    #[Test]
    public function indexWithSparseFieldsetsCombinedWithSort(): void
    {
        $this->createAndSaveEntity(['title' => 'Zulu', 'body' => 'Z body']);
        $this->createAndSaveEntity(['title' => 'Alpha', 'body' => 'A body']);

        $doc = $this->controller->index('article', [
            'sort' => 'title',
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertCount(2, $array['data']);
        $this->assertSame('Alpha', $array['data'][0]['attributes']['title']);
        $this->assertSame('Zulu', $array['data'][1]['attributes']['title']);
        // Body should be filtered out.
        $this->assertArrayNotHasKey('body', $array['data'][0]['attributes']);
    }

    #[Test]
    public function indexWithoutSparseFieldsetsReturnsAllAttributes(): void
    {
        $this->createAndSaveEntity(['title' => 'Full', 'body' => 'All fields', 'summary' => 'Sum']);

        $doc = $this->controller->index('article');
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        $this->assertArrayHasKey('title', $array['data'][0]['attributes']);
        $this->assertArrayHasKey('body', $array['data'][0]['attributes']);
        $this->assertArrayHasKey('summary', $array['data'][0]['attributes']);
    }

    // --- Sparse Fieldsets on Show (GET single) ---

    #[Test]
    public function showWithSparseFieldsetsIncludesOnlyRequestedFields(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Post', 'body' => 'Content', 'summary' => 'Brief']);

        $doc = $this->controller->show('article', $entity->uuid(), [
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertArrayHasKey('title', $array['data']['attributes']);
        $this->assertArrayNotHasKey('body', $array['data']['attributes']);
        $this->assertArrayNotHasKey('summary', $array['data']['attributes']);
    }

    #[Test]
    public function showWithSparseFieldsetsPreservesIdAndType(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Post', 'body' => 'Content']);

        $doc = $this->controller->show('article', $entity->uuid(), [
            'fields' => ['article' => 'title'],
        ]);
        $array = $doc->toArray();

        $this->assertSame('article', $array['data']['type']);
        $this->assertSame($entity->uuid(), $array['data']['id']);
        $this->assertSame(['title' => 'Post'], $array['data']['attributes']);
    }

    #[Test]
    public function showWithoutSparseFieldsetsReturnsAllAttributes(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Post', 'body' => 'Content']);

        $doc = $this->controller->show('article', $entity->uuid());
        $array = $doc->toArray();

        $this->assertArrayHasKey('title', $array['data']['attributes']);
        $this->assertArrayHasKey('body', $array['data']['attributes']);
    }

    #[Test]
    public function showWithSparseFieldsetsForDifferentTypeIsIgnored(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Post', 'body' => 'Content']);

        $doc = $this->controller->show('article', $entity->uuid(), [
            'fields' => ['page' => 'title'],
        ]);
        $array = $doc->toArray();

        // Fieldset for 'page' doesn't apply to 'article' — all attributes returned.
        $this->assertArrayHasKey('title', $array['data']['attributes']);
        $this->assertArrayHasKey('body', $array['data']['attributes']);
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
