<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SchemaController::class)]
final class SchemaControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private SchemaController $controller;

    protected function setUp(): void
    {
        $storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $storage,
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
            translatable: true,
        ));

        $schemaPresenter = new SchemaPresenter();

        $this->controller = new SchemaController(
            $this->entityTypeManager,
            $schemaPresenter,
        );
    }

    #[Test]
    public function showReturnsSchemaForEntityType(): void
    {
        $doc = $this->controller->show('article');
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertArrayHasKey('meta', $array);
        $this->assertArrayHasKey('schema', $array['meta']);

        $schema = $array['meta']['schema'];
        $this->assertSame('Article', $schema['title']);
        $this->assertSame('object', $schema['type']);
        $this->assertSame('article', $schema['x-entity-type']);
        $this->assertTrue($schema['x-translatable']);
    }

    #[Test]
    public function showIncludesSystemPropertiesInSchema(): void
    {
        $doc = $this->controller->show('article');
        $schema = $doc->toArray()['meta']['schema'];

        $this->assertArrayHasKey('properties', $schema);
        $properties = $schema['properties'];

        // id property.
        $this->assertArrayHasKey('id', $properties);
        $this->assertSame('integer', $properties['id']['type']);
        $this->assertTrue($properties['id']['readOnly']);
        $this->assertSame('hidden', $properties['id']['x-widget']);

        // uuid property.
        $this->assertArrayHasKey('uuid', $properties);
        $this->assertSame('string', $properties['uuid']['type']);
        $this->assertSame('uuid', $properties['uuid']['format']);

        // title property (label key).
        $this->assertArrayHasKey('title', $properties);
        $this->assertSame('string', $properties['title']['type']);
        $this->assertSame('text', $properties['title']['x-widget']);
    }

    #[Test]
    public function showIncludesSelfLink(): void
    {
        $doc = $this->controller->show('article');
        $array = $doc->toArray();

        $this->assertArrayHasKey('links', $array);
        $this->assertSame('/api/schema/article', $array['links']['self']);
    }

    #[Test]
    public function showReturnsErrorForUnknownEntityType(): void
    {
        $doc = $this->controller->show('nonexistent');
        $array = $doc->toArray();

        $this->assertSame(404, $doc->statusCode);
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
        $this->assertStringContainsString('nonexistent', $array['errors'][0]['detail']);
    }

    #[Test]
    public function showIncludesFieldDefinitionsInSchema(): void
    {
        $storage = new InMemoryEntityStorage('node');
        $manager = new EntityTypeManager(new EventDispatcher(), fn() => $storage);

        $manager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Content',
            class: TestEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            fieldDefinitions: [
                'status' => [
                    'type' => 'boolean',
                    'label' => 'Published',
                    'weight' => 10,
                ],
                'uid' => [
                    'type' => 'entity_reference',
                    'label' => 'Author',
                    'target_entity_type_id' => 'user',
                    'weight' => 20,
                ],
            ],
        ));

        $controller = new SchemaController($manager, new SchemaPresenter());
        $doc = $controller->show('node');
        $schema = $doc->toArray()['meta']['schema'];

        $this->assertSame(200, $doc->statusCode);
        $this->assertArrayHasKey('status', $schema['properties']);
        $this->assertSame('boolean', $schema['properties']['status']['type']);
        $this->assertSame('boolean', $schema['properties']['status']['x-widget']);
        $this->assertSame('Published', $schema['properties']['status']['x-label']);

        $this->assertArrayHasKey('uid', $schema['properties']);
        $this->assertSame('entity_autocomplete', $schema['properties']['uid']['x-widget']);
        $this->assertSame('user', $schema['properties']['uid']['x-target-type']);
    }

    #[Test]
    public function showAcceptsFieldAccessContext(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $handler = new EntityAccessHandler([]);

        $controller = new SchemaController(
            $this->entityTypeManager,
            new SchemaPresenter(),
            $handler,
            $account,
        );

        $doc = $controller->show('article');
        $schema = $doc->toArray()['meta']['schema'];

        $this->assertSame(200, $doc->statusCode);

        // System properties are always present — not subject to field access.
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertArrayHasKey('uuid', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
    }
}
