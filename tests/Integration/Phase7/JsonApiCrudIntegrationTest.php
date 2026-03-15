<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Full CRUD lifecycle integration test for the JSON:API layer.
 *
 * Exercises: waaseyaa/api (JsonApiController, ResourceSerializer, JsonApiDocument)
 * with waaseyaa/entity (EntityTypeManager, EntityType) using in-memory storage.
 */
#[CoversNothing]
final class JsonApiCrudIntegrationTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private JsonApiController $controller;
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('node');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
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

        $serializer = new ResourceSerializer($this->entityTypeManager);
        $this->controller = new JsonApiController($this->entityTypeManager, $serializer);
    }

    #[Test]
    public function storeCreatesEntityAndReturns201(): void
    {
        $doc = $this->controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'First Article',
                    'type' => 'article',
                    'status' => 1,
                ],
            ],
        ]);

        $this->assertSame(201, $doc->statusCode);

        $array = $doc->toArray();
        $this->assertArrayHasKey('data', $array);
        $this->assertSame('node', $array['data']['type']);
        $this->assertSame('First Article', $array['data']['attributes']['title']);
        $this->assertArrayHasKey('meta', $array);
        $this->assertTrue($array['meta']['created']);
    }

    #[Test]
    public function showReturnsCreatedEntity(): void
    {
        // Create a node first.
        $this->controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Show Test',
                    'type' => 'article',
                    'status' => 1,
                ],
            ],
        ]);

        $doc = $this->controller->show('node', 1);

        $this->assertSame(200, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertSame('node', $array['data']['type']);
        $this->assertSame('Show Test', $array['data']['attributes']['title']);
        $this->assertArrayHasKey('links', $array);
        $this->assertStringContainsString('/api/node/', $array['links']['self']);
    }

    #[Test]
    public function indexReturnsCollectionWithPaginationMeta(): void
    {
        // Create multiple nodes.
        for ($i = 1; $i <= 3; $i++) {
            $this->controller->store('node', [
                'data' => [
                    'type' => 'node',
                    'attributes' => [
                        'title' => "Node {$i}",
                        'type' => 'article',
                        'status' => 1,
                    ],
                ],
            ]);
        }

        $doc = $this->controller->index('node');

        $this->assertSame(200, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertCount(3, $array['data']);
        $this->assertArrayHasKey('meta', $array);
        $this->assertSame(3, $array['meta']['total']);
        $this->assertSame(0, $array['meta']['offset']);
        $this->assertArrayHasKey('links', $array);
    }

    #[Test]
    public function updateModifiesEntityAttributes(): void
    {
        // Create a node.
        $storeDoc = $this->controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Original Title',
                    'type' => 'article',
                    'status' => 1,
                ],
            ],
        ]);

        $storeArray = $storeDoc->toArray();
        $uuid = $storeArray['data']['id'];

        // Update it.
        $doc = $this->controller->update('node', 1, [
            'data' => [
                'type' => 'node',
                'id' => $uuid,
                'attributes' => [
                    'title' => 'Updated Title',
                ],
            ],
        ]);

        $this->assertSame(200, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertSame('Updated Title', $array['data']['attributes']['title']);

        // Verify persistence.
        $showDoc = $this->controller->show('node', 1);
        $showArray = $showDoc->toArray();
        $this->assertSame('Updated Title', $showArray['data']['attributes']['title']);
    }

    #[Test]
    public function destroyDeletesEntityAndReturns204(): void
    {
        // Create a node.
        $this->controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'To Delete',
                    'type' => 'article',
                    'status' => 1,
                ],
            ],
        ]);

        $doc = $this->controller->destroy('node', 1);

        $this->assertSame(204, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertNull($array['data']);
        $this->assertTrue($array['meta']['deleted']);

        // Verify entity is gone.
        $showDoc = $this->controller->show('node', 1);
        $this->assertSame(404, $showDoc->statusCode);
    }

    #[Test]
    public function fullCrudLifecycle(): void
    {
        // CREATE
        $createDoc = $this->controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Lifecycle Test',
                    'type' => 'article',
                    'status' => 1,
                ],
            ],
        ]);
        $this->assertSame(201, $createDoc->statusCode);
        $createArray = $createDoc->toArray();
        $uuid = $createArray['data']['id'];

        // READ
        $readDoc = $this->controller->show('node', 1);
        $this->assertSame(200, $readDoc->statusCode);
        $readArray = $readDoc->toArray();
        $this->assertSame('Lifecycle Test', $readArray['data']['attributes']['title']);

        // LIST
        $listDoc = $this->controller->index('node');
        $this->assertSame(200, $listDoc->statusCode);
        $listArray = $listDoc->toArray();
        $this->assertCount(1, $listArray['data']);

        // UPDATE
        $updateDoc = $this->controller->update('node', 1, [
            'data' => [
                'type' => 'node',
                'id' => $uuid,
                'attributes' => [
                    'title' => 'Updated Lifecycle Test',
                    'status' => 0,
                ],
            ],
        ]);
        $this->assertSame(200, $updateDoc->statusCode);
        $updateArray = $updateDoc->toArray();
        $this->assertSame('Updated Lifecycle Test', $updateArray['data']['attributes']['title']);

        // DELETE
        $deleteDoc = $this->controller->destroy('node', 1);
        $this->assertSame(204, $deleteDoc->statusCode);

        // VERIFY GONE
        $goneDoc = $this->controller->show('node', 1);
        $this->assertSame(404, $goneDoc->statusCode);

        // VERIFY LIST EMPTY
        $emptyListDoc = $this->controller->index('node');
        $emptyListArray = $emptyListDoc->toArray();
        $this->assertCount(0, $emptyListArray['data']);
        $this->assertSame(0, $emptyListArray['meta']['total']);
    }

    #[Test]
    public function showNonExistentReturns404(): void
    {
        $doc = $this->controller->show('node', 999);

        $this->assertSame(404, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
        $this->assertSame('Not Found', $array['errors'][0]['title']);
    }

    #[Test]
    public function storeWithWrongTypeReturns422(): void
    {
        $doc = $this->controller->store('node', [
            'data' => [
                'type' => 'article',
                'attributes' => [
                    'title' => 'Wrong Type',
                ],
            ],
        ]);

        $this->assertSame(422, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('422', $array['errors'][0]['status']);
    }

    #[Test]
    public function updateNonExistentReturns404(): void
    {
        $doc = $this->controller->update('node', 999, [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Does Not Exist',
                ],
            ],
        ]);

        $this->assertSame(404, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    #[Test]
    public function updateWithMismatchedIdReturns409(): void
    {
        // Create a node.
        $this->controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Conflict Test',
                    'type' => 'article',
                ],
            ],
        ]);

        $doc = $this->controller->update('node', 1, [
            'data' => [
                'type' => 'node',
                'id' => 'wrong-uuid-value',
                'attributes' => [
                    'title' => 'Should Fail',
                ],
            ],
        ]);

        $this->assertSame(409, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('409', $array['errors'][0]['status']);
        $this->assertSame('Conflict', $array['errors'][0]['title']);
    }

    #[Test]
    public function storeWithMissingDataReturns400(): void
    {
        $doc = $this->controller->store('node', []);

        $this->assertSame(400, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('400', $array['errors'][0]['status']);
    }

    #[Test]
    public function destroyNonExistentReturns404(): void
    {
        $doc = $this->controller->destroy('node', 999);

        $this->assertSame(404, $doc->statusCode);
    }

    #[Test]
    public function showUnknownEntityTypeReturns404(): void
    {
        $doc = $this->controller->show('unknown_type', 1);

        $this->assertSame(404, $doc->statusCode);
    }

    #[Test]
    public function documentSerializesToValidJsonApiStructure(): void
    {
        $this->controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'JSON Structure Test',
                    'type' => 'article',
                    'status' => 1,
                ],
            ],
        ]);

        $doc = $this->controller->show('node', 1);
        $array = $doc->toArray();

        // Verify JSON:API version.
        $this->assertArrayHasKey('jsonapi', $array);
        $this->assertSame('1.1', $array['jsonapi']['version']);

        // Verify data structure.
        $this->assertArrayHasKey('data', $array);
        $this->assertArrayHasKey('type', $array['data']);
        $this->assertArrayHasKey('id', $array['data']);
        $this->assertArrayHasKey('attributes', $array['data']);

        // Verify valid JSON encoding.
        $json = json_encode($array, JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($array, $decoded);
    }
}
