<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Introspection\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;
use Waaseyaa\Bimaaji\Introspection\Entity\EntityIntrospectionProvider;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[CoversClass(EntityIntrospectionProvider::class)]
final class EntityIntrospectionProviderTest extends TestCase
{
    #[Test]
    public function it_implements_graph_section_provider_interface(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $provider = new EntityIntrospectionProvider($manager);

        $this->assertInstanceOf(GraphSectionProviderInterface::class, $provider);
    }

    #[Test]
    public function get_key_returns_entities(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $provider = new EntityIntrospectionProvider($manager);

        $this->assertSame('entities', $provider->getKey());
    }

    #[Test]
    public function provide_returns_empty_section_when_no_entity_types(): void
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);

        $provider = new EntityIntrospectionProvider($manager);
        $section = $provider->provide();

        $this->assertInstanceOf(GraphSection::class, $section);
        $this->assertSame('entities', $section->key);
        $this->assertSame('1.0', $section->version);
        $this->assertSame([], $section->data);
    }

    #[Test]
    public function provide_builds_section_from_entity_type_definitions(): void
    {
        $nodeType = $this->createStub(EntityTypeInterface::class);
        $nodeType->method('id')->willReturn('node');
        $nodeType->method('getLabel')->willReturn('Content');
        $nodeType->method('getClass')->willReturn('Waaseyaa\\Node\\Entity\\Node');
        $nodeType->method('getKeys')->willReturn(['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title']);
        $nodeType->method('getFieldDefinitions')->willReturn([
            'title' => ['type' => 'string', 'label' => 'Title'],
            'body' => ['type' => 'text_long', 'label' => 'Body'],
        ]);
        $nodeType->method('getGroup')->willReturn('content');
        $nodeType->method('getDescription')->willReturn('A piece of content.');
        $nodeType->method('isRevisionable')->willReturn(true);
        $nodeType->method('isTranslatable')->willReturn(false);

        $userType = $this->createStub(EntityTypeInterface::class);
        $userType->method('id')->willReturn('user');
        $userType->method('getLabel')->willReturn('User');
        $userType->method('getClass')->willReturn('Waaseyaa\\User\\Entity\\User');
        $userType->method('getKeys')->willReturn(['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name']);
        $userType->method('getFieldDefinitions')->willReturn([]);
        $userType->method('getGroup')->willReturn(null);
        $userType->method('getDescription')->willReturn(null);
        $userType->method('isRevisionable')->willReturn(false);
        $userType->method('isTranslatable')->willReturn(false);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'node' => $nodeType,
            'user' => $userType,
        ]);

        $provider = new EntityIntrospectionProvider($manager);
        $section = $provider->provide();

        $this->assertSame('entities', $section->key);
        $this->assertSame('1.0', $section->version);

        $data = $section->data;
        $this->assertArrayHasKey('node', $data);
        $this->assertArrayHasKey('user', $data);

        $this->assertSame([
            'label' => 'Content',
            'class' => 'Waaseyaa\\Node\\Entity\\Node',
            'keys' => ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title'],
            'fields' => [
                'title' => ['type' => 'string', 'label' => 'Title'],
                'body' => ['type' => 'text_long', 'label' => 'Body'],
            ],
            'group' => 'content',
            'description' => 'A piece of content.',
            'revisionable' => true,
            'translatable' => false,
        ], $data['node']);

        $this->assertSame([
            'label' => 'User',
            'class' => 'Waaseyaa\\User\\Entity\\User',
            'keys' => ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
            'fields' => [],
            'group' => null,
            'description' => null,
            'revisionable' => false,
            'translatable' => false,
        ], $data['user']);
    }
}
