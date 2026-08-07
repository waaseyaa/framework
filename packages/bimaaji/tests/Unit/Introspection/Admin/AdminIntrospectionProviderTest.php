<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Introspection\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;
use Waaseyaa\Bimaaji\Introspection\Admin\AdminIntrospectionProvider;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[CoversClass(AdminIntrospectionProvider::class)]
final class AdminIntrospectionProviderTest extends TestCase
{
    #[Test]
    public function it_implements_graph_section_provider_interface(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $provider = new AdminIntrospectionProvider($manager);

        $this->assertInstanceOf(GraphSectionProviderInterface::class, $provider);
    }

    #[Test]
    public function get_key_returns_admin(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $provider = new AdminIntrospectionProvider($manager);

        $this->assertSame('admin', $provider->getKey());
    }

    #[Test]
    public function provide_returns_empty_section_when_no_entity_types(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $provider = new AdminIntrospectionProvider($manager);
        $section = $provider->provide();

        $this->assertInstanceOf(GraphSection::class, $section);
        $this->assertSame('admin', $section->key);
        $this->assertSame('1.0', $section->version);
        $this->assertSame([], $section->data);
    }

    #[Test]
    public function provide_excludes_entity_types_without_group(): void
    {
        $ungrouped = $this->createStub(EntityTypeInterface::class);
        $ungrouped->method('id')->willReturn('config_entity');
        $ungrouped->method('getGroup')->willReturn(null);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'config_entity' => $ungrouped,
        ]);

        $provider = new AdminIntrospectionProvider($manager);
        $section = $provider->provide();

        $this->assertSame([], $section->data);
    }

    #[Test]
    public function provide_includes_entity_types_with_group(): void
    {
        $nodeType = $this->createStub(EntityTypeInterface::class);
        $nodeType->method('id')->willReturn('node');
        $nodeType->method('getLabel')->willReturn('Content');
        $nodeType->method('getGroup')->willReturn('content');
        $nodeType->method('getDescription')->willReturn('A piece of content.');
        $nodeType->method('getFieldDefinitions')->willReturn([
            'title' => ['type' => 'string', 'label' => 'Title'],
            'body' => ['type' => 'text_long', 'label' => 'Body'],
        ]);
        $nodeType->method('getKeys')->willReturn([
            'id' => 'nid',
            'uuid' => 'uuid',
            'label' => 'title',
        ]);

        $ungrouped = $this->createStub(EntityTypeInterface::class);
        $ungrouped->method('id')->willReturn('user');
        $ungrouped->method('getGroup')->willReturn(null);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'node' => $nodeType,
            'user' => $ungrouped,
        ]);

        $provider = new AdminIntrospectionProvider($manager);
        $section = $provider->provide();

        $this->assertSame('admin', $section->key);
        $this->assertSame('1.0', $section->version);

        $data = $section->data;
        $this->assertArrayHasKey('node', $data);
        $this->assertArrayNotHasKey('user', $data);

        $this->assertSame([
            'label' => 'Content',
            'group' => 'content',
            'description' => 'A piece of content.',
            'fields' => ['title', 'body'],
            'capabilities' => [
                'list' => true,
                'get' => true,
                'create' => true,
                'update' => true,
                'delete' => true,
            ],
        ], $data['node']);
    }

    #[Test]
    public function provide_sets_limited_capabilities_for_config_entities(): void
    {
        $configType = $this->createStub(EntityTypeInterface::class);
        $configType->method('id')->willReturn('menu');
        $configType->method('getLabel')->willReturn('Menu');
        $configType->method('getGroup')->willReturn('structure');
        $configType->method('getDescription')->willReturn(null);
        $configType->method('getFieldDefinitions')->willReturn([
            'name' => ['type' => 'string', 'label' => 'Name'],
        ]);
        $configType->method('getKeys')->willReturn([
            'id' => 'id',
        ]);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'menu' => $configType,
        ]);

        $provider = new AdminIntrospectionProvider($manager);
        $section = $provider->provide();

        $data = $section->data;
        $this->assertArrayHasKey('menu', $data);

        $this->assertSame([
            'label' => 'Menu',
            'group' => 'structure',
            'description' => null,
            'fields' => ['name'],
            'capabilities' => [
                'list' => true,
                'get' => true,
                'create' => false,
                'update' => false,
                'delete' => false,
            ],
        ], $data['menu']);
    }
}
