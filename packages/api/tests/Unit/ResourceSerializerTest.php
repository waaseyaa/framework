<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use Waaseyaa\Api\JsonApiResource;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Testing\Factory\EntityTypeFactory;
use Waaseyaa\Field\FieldDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(ResourceSerializer::class)]
final class ResourceSerializerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private ResourceSerializer $serializer;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerEntityType(EntityTypeFactory::create(
            'article',
            [
                'status' => new FieldDefinition(name: 'status', type: 'boolean'),
                'promote' => new FieldDefinition(name: 'promote', type: 'boolean'),
                'created' => new FieldDefinition(name: 'created', type: 'timestamp'),
                'changed' => new FieldDefinition(name: 'changed', type: 'timestamp'),
            ],
            keys: TestEntity::definitionKeys(),
            class: TestEntity::class,
            label: 'Article',
        ));

        $this->serializer = new ResourceSerializer($this->entityTypeManager);
    }

    #[Test]
    public function serializeOmitsAlwaysInternalCredentialFields(): void
    {
        // Credential-like raw _data keys must never leak via the JSON:API
        // serializer, even when no FieldDefinition exists for them.
        $entity = new TestEntity([
            'id' => 42,
            'uuid' => 'abc-123-def',
            'title' => 'My Article',
            'pass' => '$2y$12$NEVER_LEAK_BCRYPT_HASH',
            'password' => 'plaintext-should-not-leak',
            'password_hash' => 'alt-credential-key',
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertArrayNotHasKey('pass', $resource->attributes);
        $this->assertArrayNotHasKey('password', $resource->attributes);
        $this->assertArrayNotHasKey('password_hash', $resource->attributes);
        $this->assertSame('My Article', $resource->attributes['title']);
    }

    #[Test]
    public function serializeOmitsFieldsMarkedInternalInSettings(): void
    {
        // Fields whose FieldDefinition sets `settings['internal'] => true`
        // (e.g. User::two_factor_secret) must not appear in serialized output.
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType(EntityTypeFactory::create(
            'article',
            [
                'two_factor_secret' => new FieldDefinition(
                    name: 'two_factor_secret',
                    type: 'string',
                    settings: ['internal' => true],
                ),
                'title' => new FieldDefinition(name: 'title', type: 'string'),
            ],
            keys: TestEntity::definitionKeys(),
            class: TestEntity::class,
            label: 'Article',
        ));
        $serializer = new ResourceSerializer($entityTypeManager);

        $entity = new TestEntity([
            'id' => 7,
            'uuid' => 'aaa-111-bbb',
            'title' => 'visible',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $resource = $serializer->serialize($entity);

        $this->assertArrayNotHasKey('two_factor_secret', $resource->attributes);
        $this->assertSame('visible', $resource->attributes['title']);
    }

    #[Test]
    public function serializeEntityToResource(): void
    {
        $entity = new TestEntity([
            'id' => 42,
            'uuid' => 'abc-123-def',
            'title' => 'My Article',
            'type' => 'blog',
            'body' => 'Content here.',
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertInstanceOf(JsonApiResource::class, $resource);
        $this->assertSame('article', $resource->type);
        $this->assertSame('abc-123-def', $resource->id);
    }

    #[Test]
    public function serializeExcludesIdAndUuidFromAttributes(): void
    {
        $entity = new TestEntity([
            'id' => 42,
            'uuid' => 'abc-123-def',
            'title' => 'My Article',
            'type' => 'blog',
            'body' => 'Content here.',
        ]);

        $resource = $this->serializer->serialize($entity);

        // id and uuid should NOT be in attributes.
        $this->assertArrayNotHasKey('id', $resource->attributes);
        $this->assertArrayNotHasKey('uuid', $resource->attributes);

        // Other fields should be in attributes.
        $this->assertSame('My Article', $resource->attributes['title']);
        $this->assertSame('blog', $resource->attributes['type']);
        $this->assertSame('Content here.', $resource->attributes['body']);
    }

    #[Test]
    public function serializeGeneratesSelfLink(): void
    {
        $entity = new TestEntity([
            'id' => 42,
            'uuid' => 'abc-123-def',
            'title' => 'Test',
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertSame('/api/article/abc-123-def', $resource->links['self']);
    }

    #[Test]
    public function serializeUsesUuidAsResourceId(): void
    {
        $entity = new TestEntity([
            'id' => 42,
            'uuid' => 'some-uuid-value',
            'title' => 'Test',
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertSame('some-uuid-value', $resource->id);
    }

    #[Test]
    public function serializeCollection(): void
    {
        $entities = [
            new TestEntity(['id' => 1, 'uuid' => 'uuid-1', 'title' => 'First']),
            new TestEntity(['id' => 2, 'uuid' => 'uuid-2', 'title' => 'Second']),
            new TestEntity(['id' => 3, 'uuid' => 'uuid-3', 'title' => 'Third']),
        ];

        $resources = $this->serializer->serializeCollection($entities);

        $this->assertCount(3, $resources);
        $this->assertContainsOnlyInstancesOf(JsonApiResource::class, $resources);
        $this->assertSame('uuid-1', $resources[0]->id);
        $this->assertSame('uuid-2', $resources[1]->id);
        $this->assertSame('uuid-3', $resources[2]->id);
    }

    #[Test]
    public function serializeEmptyCollection(): void
    {
        $resources = $this->serializer->serializeCollection([]);

        $this->assertSame([], $resources);
    }

    #[Test]
    public function serializeWithCustomBasePath(): void
    {
        $serializer = new ResourceSerializer($this->entityTypeManager, '/jsonapi');

        $entity = new TestEntity([
            'id' => 1,
            'uuid' => 'uuid-custom',
            'title' => 'Test',
        ]);

        $resource = $serializer->serialize($entity);

        $this->assertSame('/jsonapi/article/uuid-custom', $resource->links['self']);
    }

    #[Test]
    public function serializeEntityWithoutExplicitUuidUsesAutoGenerated(): void
    {
        $entity = new TestEntity([
            'id' => 1,
            'title' => 'No Explicit UUID',
        ]);

        $resource = $this->serializer->serialize($entity);

        // UUID should be auto-generated by EntityBase.
        $this->assertNotEmpty($resource->id);
        $this->assertSame($entity->uuid(), $resource->id);
    }

    #[Test]
    public function serializeCastsBooleanFieldsToNativeBooleans(): void
    {
        $entity = new TestEntity([
            'id' => 1,
            'uuid' => 'uuid-bool',
            'title' => 'Test',
            'status' => 1,
            'promote' => 0,
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertTrue($resource->attributes['status']);
        $this->assertFalse($resource->attributes['promote']);
        $this->assertIsBool($resource->attributes['status']);
        $this->assertIsBool($resource->attributes['promote']);
    }

    #[Test]
    public function serializeCastsTimestampFieldsToIso8601(): void
    {
        $timestamp = 1709510400; // 2024-03-04T00:00:00+00:00
        $entity = new TestEntity([
            'id' => 1,
            'uuid' => 'uuid-ts',
            'title' => 'Test',
            'created' => $timestamp,
            'changed' => $timestamp,
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertIsString($resource->attributes['created']);
        $this->assertStringContainsString('2024-03-04', $resource->attributes['created']);
        $this->assertIsString($resource->attributes['changed']);
        $this->assertStringContainsString('2024-03-04', $resource->attributes['changed']);
    }

    #[Test]
    public function serializeCastsZeroTimestampToNull(): void
    {
        $entity = new TestEntity([
            'id' => 1,
            'uuid' => 'uuid-ts0',
            'title' => 'Test',
            'created' => 0,
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertNull($resource->attributes['created']);
    }

    #[Test]
    public function serializeCastsNullTimestampToNull(): void
    {
        $entity = new TestEntity([
            'id' => 1,
            'uuid' => 'uuid-null-ts',
            'title' => 'Test',
            'created' => null,
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertNull($resource->attributes['created']);
    }
}
