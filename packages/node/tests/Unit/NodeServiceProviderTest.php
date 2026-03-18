<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;

#[CoversClass(NodeServiceProvider::class)]
final class NodeServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_node_and_node_type(): void
    {
        $provider = new NodeServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(2, $entityTypes);
        $this->assertSame('node', $entityTypes[0]->id());
        $this->assertSame(Node::class, $entityTypes[0]->getClass());
        $this->assertSame('node_type', $entityTypes[1]->id());
        $this->assertSame(NodeType::class, $entityTypes[1]->getClass());
    }

    #[Test]
    public function node_entity_type_has_field_definitions(): void
    {
        $provider = new NodeServiceProvider();
        $provider->register();

        $fields = $provider->getEntityTypes()[0]->getFieldDefinitions();

        $this->assertArrayHasKey('title', $fields);
        $this->assertSame('string', $fields['title']['type']);
        $this->assertTrue($fields['title']['required']);

        $this->assertArrayHasKey('type', $fields);
        $this->assertSame('string', $fields['type']['type']);
        $this->assertTrue($fields['type']['required']);
        $this->assertTrue($fields['type']['readOnly']);

        $this->assertArrayHasKey('slug', $fields);
        $this->assertSame('string', $fields['slug']['type']);
        $this->assertTrue($fields['slug']['required']);

        $this->assertArrayHasKey('status', $fields);
        $this->assertArrayHasKey('promote', $fields);
        $this->assertArrayHasKey('sticky', $fields);

        $this->assertArrayHasKey('uid', $fields);
        $this->assertSame('user', $fields['uid']['target_entity_type_id']);
        $this->assertArrayNotHasKey('settings', $fields['uid']);

        $this->assertArrayHasKey('created', $fields);
        $this->assertArrayHasKey('changed', $fields);
    }

    #[Test]
    public function node_type_has_no_field_definitions(): void
    {
        $provider = new NodeServiceProvider();
        $provider->register();

        $fields = $provider->getEntityTypes()[1]->getFieldDefinitions();

        $this->assertSame([], $fields);
    }
}
