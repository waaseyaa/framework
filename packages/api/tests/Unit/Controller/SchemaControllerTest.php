<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Controller;

use Aurora\Api\Controller\SchemaController;
use Aurora\Api\Schema\SchemaPresenter;
use Aurora\Api\Tests\Fixtures\InMemoryEntityStorage;
use Aurora\Api\Tests\Fixtures\TestEntity;
use Aurora\Entity\EntityType;
use Aurora\Entity\EntityTypeManager;
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
}
