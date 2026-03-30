<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(JsonApiController::class)]
final class JsonApiControllerConfigEntityTest extends TestCase
{
    #[Test]
    public function storeAutoGeneratesMachineNameForConfigEntity(): void
    {
        $controller = $this->createConfigController($configStorage);

        $data = [
            'data' => [
                'type' => 'node_type',
                'attributes' => [
                    'name' => 'Blog Post',
                ],
            ],
        ];

        $doc = $controller->store('node_type', $data);
        $array = $doc->toArray();

        $this->assertSame(201, $doc->statusCode);
        $this->assertSame('Blog Post', $array['data']['attributes']['name']);
        // Config entity ID is the machine name, serialized as top-level 'id'.
        $this->assertSame('blog_post', $array['data']['id']);
    }

    #[Test]
    public function storePreservesExplicitMachineNameForConfigEntity(): void
    {
        $controller = $this->createConfigController($configStorage);

        $data = [
            'data' => [
                'type' => 'node_type',
                'attributes' => [
                    'name' => 'Blog Post',
                    'type' => 'custom_blog',
                ],
            ],
        ];

        $doc = $controller->store('node_type', $data);
        $array = $doc->toArray();

        $this->assertSame(201, $doc->statusCode);
        // Explicit machine name should be preserved, not overwritten.
        $this->assertSame('custom_blog', $array['data']['id']);
    }

    #[Test]
    public function storeRejectsConfigEntityWhenLabelProducesEmptyMachineName(): void
    {
        $controller = $this->createConfigController($configStorage);

        $data = [
            'data' => [
                'type' => 'node_type',
                'attributes' => [
                    'name' => '!!!',
                ],
            ],
        ];

        $doc = $controller->store('node_type', $data);

        $this->assertSame(422, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertArrayHasKey('errors', $array);
        $this->assertStringContainsString('Cannot generate a machine name', $array['errors'][0]['detail']);
    }

    // --- JSON:API spec compliance ---

    #[Test]
    public function allResponsesIncludeJsonApiVersion(): void
    {
        $storage = new InMemoryEntityStorage('article');

        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $storage,
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

        $controller = new JsonApiController(
            $entityTypeManager,
            new ResourceSerializer($entityTypeManager),
        );

        // Index.
        $doc = $controller->index('article');
        $this->assertSame('1.1', $doc->toArray()['jsonapi']['version']);

        // Show error.
        $doc = $controller->show('article', 9999);
        $this->assertSame('1.1', $doc->toArray()['jsonapi']['version']);

        // Store.
        $doc = $controller->store('article', [
            'data' => ['type' => 'article', 'attributes' => ['title' => 'Test']],
        ]);
        $this->assertSame('1.1', $doc->toArray()['jsonapi']['version']);
    }

    // --- Helpers ---

    private function createConfigController(?InMemoryEntityStorage &$configStorage = null): JsonApiController
    {
        $configStorage = new class('node_type') extends InMemoryEntityStorage {
            public function create(array $values = []): \Waaseyaa\Entity\EntityInterface
            {
                return new TestEntity(
                    values: $values,
                    entityTypeId: 'node_type',
                    entityKeys: ['id' => 'type', 'label' => 'name'],
                );
            }
        };

        $configManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $configStorage,
        );
        $configManager->registerEntityType(new EntityType(
            id: 'node_type',
            label: 'Content Type',
            class: TestEntity::class,
            keys: ['id' => 'type', 'label' => 'name'],
        ));

        return new JsonApiController(
            $configManager,
            new ResourceSerializer($configManager),
        );
    }
}
