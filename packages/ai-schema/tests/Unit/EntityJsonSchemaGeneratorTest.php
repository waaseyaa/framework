<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Schema\Tests\Unit;

use Waaseyaa\AI\Schema\EntityJsonSchemaGenerator;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityJsonSchemaGenerator::class)]
final class EntityJsonSchemaGeneratorTest extends TestCase
{
    private EntityTypeManagerInterface $entityTypeManager;

    protected function setUp(): void
    {
        $this->entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
    }

    /** @return EntityTypeManagerInterface&MockObject */
    private function expectEntityTypeManager(): EntityTypeManagerInterface
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $this->entityTypeManager = $manager;

        return $manager;
    }

    #[Test]
    public function generateProducesValidJsonSchemaStructure(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: \stdClass::class,
            keys: [
                'id' => 'nid',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
                'langcode' => 'langcode',
            ],
        );

        $this->expectEntityTypeManager()->expects(self::once())
            ->method('getDefinition')
            ->with('node')
            ->willReturn($entityType);

        $generator = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $schema = $generator->generate('node');

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertSame('Content', $schema['title']);
        $this->assertSame('Schema for Content entities', $schema['description']);
        $this->assertSame('object', $schema['type']);
        $this->assertTrue($schema['additionalProperties']);
    }

    #[Test]
    public function generateIncludesEntityKeysAsProperties(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: \stdClass::class,
            keys: [
                'id' => 'nid',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
                'langcode' => 'langcode',
            ],
        );

        $this->expectEntityTypeManager()->expects(self::once())
            ->method('getDefinition')
            ->with('node')
            ->willReturn($entityType);

        $generator = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $schema = $generator->generate('node');

        $properties = $schema['properties'];

        // ID property.
        $this->assertArrayHasKey('nid', $properties);
        $this->assertSame(['integer', 'string'], $properties['nid']['type']);

        // UUID property.
        $this->assertArrayHasKey('uuid', $properties);
        $this->assertSame('string', $properties['uuid']['type']);
        $this->assertSame('uuid', $properties['uuid']['format']);

        // Label property.
        $this->assertArrayHasKey('title', $properties);
        $this->assertSame('string', $properties['title']['type']);

        // Bundle property.
        $this->assertArrayHasKey('type', $properties);
        $this->assertSame('string', $properties['type']['type']);

        // Langcode property.
        $this->assertArrayHasKey('langcode', $properties);
        $this->assertSame('string', $properties['langcode']['type']);
    }

    #[Test]
    public function generateSetsRequiredFieldsCorrectly(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: \stdClass::class,
            keys: [
                'id' => 'nid',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ],
        );

        $this->expectEntityTypeManager()->expects(self::once())
            ->method('getDefinition')
            ->with('node')
            ->willReturn($entityType);

        $generator = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $schema = $generator->generate('node');

        // ID, UUID, label, and bundle should be required.
        $this->assertContains('nid', $schema['required']);
        $this->assertContains('uuid', $schema['required']);
        $this->assertContains('title', $schema['required']);
        $this->assertContains('type', $schema['required']);

        // Langcode is not required (not present in keys).
        $this->assertNotContains('langcode', $schema['required']);
    }

    #[Test]
    public function generateHandlesEntityTypeWithoutBundle(): void
    {
        $entityType = new EntityType(
            id: 'user',
            label: 'User',
            class: \stdClass::class,
            keys: [
                'id' => 'uid',
                'uuid' => 'uuid',
                'label' => 'name',
            ],
        );

        $this->expectEntityTypeManager()->expects(self::once())
            ->method('getDefinition')
            ->with('user')
            ->willReturn($entityType);

        $generator = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $schema = $generator->generate('user');

        $this->assertArrayNotHasKey('bundle', $schema['properties']);
        $this->assertNotContains('bundle', $schema['required']);
    }

    #[Test]
    public function generateIncludesRevisionKeyWhenRevisionable(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: \stdClass::class,
            keys: [
                'id' => 'nid',
                'uuid' => 'uuid',
                'label' => 'title',
                'revision' => 'vid',
            ],
            revisionable: true,
        );

        $this->expectEntityTypeManager()->expects(self::once())
            ->method('getDefinition')
            ->with('node')
            ->willReturn($entityType);

        $generator = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $schema = $generator->generate('node');

        $this->assertArrayHasKey('vid', $schema['properties']);
        $this->assertSame('integer', $schema['properties']['vid']['type']);
    }

    #[Test]
    public function generateExcludesRevisionKeyWhenNotRevisionable(): void
    {
        $entityType = new EntityType(
            id: 'user',
            label: 'User',
            class: \stdClass::class,
            keys: [
                'id' => 'uid',
                'uuid' => 'uuid',
                'label' => 'name',
                'revision' => 'vid',
            ],
            revisionable: false,
        );

        $this->expectEntityTypeManager()->expects(self::once())
            ->method('getDefinition')
            ->with('user')
            ->willReturn($entityType);

        $generator = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $schema = $generator->generate('user');

        $this->assertArrayNotHasKey('vid', $schema['properties']);
    }

    #[Test]
    public function generateAllProducesSchemasForAllEntityTypes(): void
    {
        $nodeType = new EntityType(
            id: 'node',
            label: 'Content',
            class: \stdClass::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $userType = new EntityType(
            id: 'user',
            label: 'User',
            class: \stdClass::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        );

        $this->entityTypeManager
            ->method('getDefinitions')
            ->willReturn(['node' => $nodeType, 'user' => $userType]);

        $this->entityTypeManager
            ->method('getDefinition')
            ->willReturnCallback(fn (string $id) => match ($id) {
                'node' => $nodeType,
                'user' => $userType,
            });

        $generator = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $schemas = $generator->generateAll();

        $this->assertCount(2, $schemas);
        $this->assertArrayHasKey('node', $schemas);
        $this->assertArrayHasKey('user', $schemas);

        $this->assertSame('Content', $schemas['node']['title']);
        $this->assertSame('User', $schemas['user']['title']);
    }

    #[Test]
    public function generateWithMinimalKeys(): void
    {
        $entityType = new EntityType(
            id: 'simple',
            label: 'Simple',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );

        $this->expectEntityTypeManager()->expects(self::once())
            ->method('getDefinition')
            ->with('simple')
            ->willReturn($entityType);

        $generator = new EntityJsonSchemaGenerator($this->entityTypeManager);
        $schema = $generator->generate('simple');

        $this->assertCount(1, $schema['properties']);
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertSame(['id'], $schema['required']);
    }
}
