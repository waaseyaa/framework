<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminBridge\CatalogBuilder;
use Waaseyaa\AdminBridge\CatalogCapabilities;
use Waaseyaa\AdminBridge\CatalogEntry;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[CoversClass(CatalogBuilder::class)]
#[CoversClass(CatalogEntry::class)]
#[CoversClass(CatalogCapabilities::class)]
final class CatalogBuilderTest extends TestCase
{
    #[Test]
    public function build_creates_entries_from_entity_definitions(): void
    {
        $nodeType = $this->createMock(EntityTypeInterface::class);
        $nodeType->method('id')->willReturn('node');
        $nodeType->method('getLabel')->willReturn('Content');

        $userType = $this->createMock(EntityTypeInterface::class);
        $userType->method('id')->willReturn('user');
        $userType->method('getLabel')->willReturn('User');

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'node' => $nodeType,
            'user' => $userType,
        ]);

        $builder = new CatalogBuilder($manager);
        $entries = $builder->build();

        $this->assertCount(2, $entries);

        $this->assertSame('node', $entries[0]->id);
        $this->assertSame('Content', $entries[0]->label);
        $this->assertTrue($entries[0]->capabilities->list);
        $this->assertTrue($entries[0]->capabilities->get);
        $this->assertFalse($entries[0]->capabilities->create);
        $this->assertFalse($entries[0]->capabilities->update);
        $this->assertFalse($entries[0]->capabilities->delete);
        $this->assertTrue($entries[0]->capabilities->schema);

        $this->assertSame('user', $entries[1]->id);
        $this->assertSame('User', $entries[1]->label);
    }

    #[Test]
    public function build_returns_empty_array_when_no_definitions(): void
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $builder = new CatalogBuilder($manager);
        $entries = $builder->build();

        $this->assertSame([], $entries);
    }

    #[Test]
    public function entries_serialize_correctly(): void
    {
        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('id')->willReturn('taxonomy_term');
        $entityType->method('getLabel')->willReturn('Taxonomy Term');

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn(['taxonomy_term' => $entityType]);

        $builder = new CatalogBuilder($manager);
        $entries = $builder->build();

        $array = $entries[0]->toArray();

        $this->assertSame([
            'id' => 'taxonomy_term',
            'label' => 'Taxonomy Term',
            'capabilities' => [
                'list' => true,
                'get' => true,
                'create' => false,
                'update' => false,
                'delete' => false,
                'schema' => true,
            ],
        ], $array);
    }
}
