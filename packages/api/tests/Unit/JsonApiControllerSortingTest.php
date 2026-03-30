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
final class JsonApiControllerSortingTest extends TestCase
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

    // --- Sort Ascending ---

    #[Test]
    public function indexSortAscendingByTitle(): void
    {
        $this->createAndSaveEntity(['title' => 'Charlie']);
        $this->createAndSaveEntity(['title' => 'Alpha']);
        $this->createAndSaveEntity(['title' => 'Bravo']);

        $doc = $this->controller->index('article', [
            'sort' => 'title',
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        $this->assertSame('Alpha', $array['data'][0]['attributes']['title']);
        $this->assertSame('Bravo', $array['data'][1]['attributes']['title']);
        $this->assertSame('Charlie', $array['data'][2]['attributes']['title']);
    }

    // --- Sort Descending ---

    #[Test]
    public function indexSortDescendingByTitle(): void
    {
        $this->createAndSaveEntity(['title' => 'Alpha']);
        $this->createAndSaveEntity(['title' => 'Charlie']);
        $this->createAndSaveEntity(['title' => 'Bravo']);

        $doc = $this->controller->index('article', [
            'sort' => '-title',
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        $this->assertSame('Charlie', $array['data'][0]['attributes']['title']);
        $this->assertSame('Bravo', $array['data'][1]['attributes']['title']);
        $this->assertSame('Alpha', $array['data'][2]['attributes']['title']);
    }

    // --- Sort Multiple Fields ---

    #[Test]
    public function indexSortMultipleFields(): void
    {
        $this->createAndSaveEntity(['title' => 'Alpha', 'body' => 'Z body']);
        $this->createAndSaveEntity(['title' => 'Alpha', 'body' => 'A body']);
        $this->createAndSaveEntity(['title' => 'Bravo', 'body' => 'M body']);

        $doc = $this->controller->index('article', [
            'sort' => 'title,body',
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        // Both Alphas first, sorted by body ASC.
        $this->assertSame('Alpha', $array['data'][0]['attributes']['title']);
        $this->assertSame('A body', $array['data'][0]['attributes']['body']);
        $this->assertSame('Alpha', $array['data'][1]['attributes']['title']);
        $this->assertSame('Z body', $array['data'][1]['attributes']['body']);
        $this->assertSame('Bravo', $array['data'][2]['attributes']['title']);
    }

    #[Test]
    public function indexSortMultipleFieldsMixedDirection(): void
    {
        $this->createAndSaveEntity(['title' => 'Alpha', 'body' => 'Z body']);
        $this->createAndSaveEntity(['title' => 'Alpha', 'body' => 'A body']);
        $this->createAndSaveEntity(['title' => 'Bravo', 'body' => 'M body']);

        $doc = $this->controller->index('article', [
            'sort' => 'title,-body',
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        // Alphas first (ASC), then within Alphas body DESC (Z before A).
        $this->assertSame('Alpha', $array['data'][0]['attributes']['title']);
        $this->assertSame('Z body', $array['data'][0]['attributes']['body']);
        $this->assertSame('Alpha', $array['data'][1]['attributes']['title']);
        $this->assertSame('A body', $array['data'][1]['attributes']['body']);
        $this->assertSame('Bravo', $array['data'][2]['attributes']['title']);
    }

    // --- Sort by numeric-like field ---

    #[Test]
    public function indexSortByNumericFieldValue(): void
    {
        $this->createAndSaveEntity(['title' => 'Heavy', 'weight' => 30]);
        $this->createAndSaveEntity(['title' => 'Light', 'weight' => 10]);
        $this->createAndSaveEntity(['title' => 'Medium', 'weight' => 20]);

        $doc = $this->controller->index('article', [
            'sort' => 'weight',
        ]);
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        $this->assertSame('Light', $array['data'][0]['attributes']['title']);
        $this->assertSame('Medium', $array['data'][1]['attributes']['title']);
        $this->assertSame('Heavy', $array['data'][2]['attributes']['title']);
    }

    // --- Default order (no sort param) ---

    #[Test]
    public function indexDefaultOrderReturnsInsertionOrder(): void
    {
        $first = $this->createAndSaveEntity(['title' => 'First Created']);
        $second = $this->createAndSaveEntity(['title' => 'Second Created']);
        $third = $this->createAndSaveEntity(['title' => 'Third Created']);

        $doc = $this->controller->index('article');
        $array = $doc->toArray();

        $this->assertCount(3, $array['data']);
        // Without sort param, entities come back in storage order (insertion order for InMemory).
        $this->assertSame($first->uuid(), $array['data'][0]['id']);
        $this->assertSame($second->uuid(), $array['data'][1]['id']);
        $this->assertSame($third->uuid(), $array['data'][2]['id']);
    }

    // --- Sort combined with pagination ---

    #[Test]
    public function indexSortCombinedWithPagination(): void
    {
        $this->createAndSaveEntity(['title' => 'Delta']);
        $this->createAndSaveEntity(['title' => 'Alpha']);
        $this->createAndSaveEntity(['title' => 'Charlie']);
        $this->createAndSaveEntity(['title' => 'Bravo']);

        $doc = $this->controller->index('article', [
            'sort' => 'title',
            'page' => ['offset' => 1, 'limit' => 2],
        ]);
        $array = $doc->toArray();

        // Sorted: Alpha, Bravo, Charlie, Delta — offset 1, limit 2 = Bravo, Charlie.
        $this->assertCount(2, $array['data']);
        $this->assertSame('Bravo', $array['data'][0]['attributes']['title']);
        $this->assertSame('Charlie', $array['data'][1]['attributes']['title']);
    }

    // --- Sort combined with filter ---

    #[Test]
    public function indexSortCombinedWithFilter(): void
    {
        $this->createAndSaveEntity(['title' => 'Charlie', 'body' => 'match']);
        $this->createAndSaveEntity(['title' => 'Alpha', 'body' => 'match']);
        $this->createAndSaveEntity(['title' => 'Bravo', 'body' => 'no-match']);

        $doc = $this->controller->index('article', [
            'filter' => ['body' => 'match'],
            'sort' => 'title',
        ]);
        $array = $doc->toArray();

        // Only matching entities, sorted by title.
        $this->assertCount(2, $array['data']);
        $this->assertSame('Alpha', $array['data'][0]['attributes']['title']);
        $this->assertSame('Charlie', $array['data'][1]['attributes']['title']);
    }

    // --- Empty sort string ---

    #[Test]
    public function indexEmptySortStringReturnsDefaultOrder(): void
    {
        $first = $this->createAndSaveEntity(['title' => 'Zulu']);
        $second = $this->createAndSaveEntity(['title' => 'Alpha']);

        $doc = $this->controller->index('article', [
            'sort' => '',
        ]);
        $array = $doc->toArray();

        $this->assertCount(2, $array['data']);
        // Empty sort = no sort applied = insertion order.
        $this->assertSame($first->uuid(), $array['data'][0]['id']);
        $this->assertSame($second->uuid(), $array['data'][1]['id']);
    }

    // --- Sort by nonexistent field ---

    #[Test]
    public function indexSortByNonexistentFieldReturnsDefaultOrder(): void
    {
        $first = $this->createAndSaveEntity(['title' => 'Zulu']);
        $second = $this->createAndSaveEntity(['title' => 'Alpha']);

        $doc = $this->controller->index('article', [
            'sort' => 'nonexistent_field',
        ]);
        $array = $doc->toArray();

        // Sorting by a nonexistent field is a silent no-op — insertion order preserved.
        $this->assertCount(2, $array['data']);
        $this->assertSame($first->uuid(), $array['data'][0]['id']);
        $this->assertSame($second->uuid(), $array['data'][1]['id']);
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
