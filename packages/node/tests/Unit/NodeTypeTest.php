<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit;

use Waaseyaa\Entity\ConfigEntityBase;
use Waaseyaa\Node\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NodeType::class)]
final class NodeTypeTest extends TestCase
{
    // -----------------------------------------------------------------
    // Construction and entity basics
    // -----------------------------------------------------------------

    public function testExtendsConfigEntityBase(): void
    {
        $type = new NodeType();
        $this->assertInstanceOf(ConfigEntityBase::class, $type);
    }

    public function testEntityTypeId(): void
    {
        $type = new NodeType();
        $this->assertSame('node_type', $type->getEntityTypeId());
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(NodeType::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testNewNodeTypeIsNew(): void
    {
        $type = new NodeType();
        $this->assertTrue($type->isNew());
    }

    public function testNodeTypeWithIdIsNotNew(): void
    {
        $type = new NodeType(['type' => 'article']);
        $this->assertFalse($type->isNew());
    }

    public function testConfigEntityHasNoUuid(): void
    {
        $type = new NodeType();

        // Config entities do not have UUIDs.
        $this->assertSame('', $type->uuid());
    }

    // -----------------------------------------------------------------
    // Type (machine name / id)
    // -----------------------------------------------------------------

    public function testGetType(): void
    {
        $type = new NodeType(['type' => 'article']);
        $this->assertSame('article', $type->getType());
    }

    public function testGetTypeReturnsId(): void
    {
        $type = new NodeType(['type' => 'page']);
        $this->assertSame($type->id(), $type->getType());
    }

    public function testGetTypeReturnsNullWhenNotSet(): void
    {
        $type = new NodeType();
        $this->assertNull($type->getType());
    }

    // -----------------------------------------------------------------
    // Name (human-readable label)
    // -----------------------------------------------------------------

    public function testGetNameViaConstructor(): void
    {
        $type = new NodeType(['type' => 'article', 'name' => 'Article']);
        $this->assertSame('Article', $type->getName());
    }

    public function testGetNameReturnsLabel(): void
    {
        $type = new NodeType(['name' => 'Page']);
        $this->assertSame('Page', $type->label());
    }

    public function testSetName(): void
    {
        $type = new NodeType(['type' => 'article', 'name' => 'Article']);
        $type->setName('Blog Post');
        $this->assertSame('Blog Post', $type->getName());
    }

    public function testSetNameReturnsSelf(): void
    {
        $type = new NodeType();
        $this->assertSame($type, $type->setName('Test'));
    }

    public function testDefaultNameIsEmpty(): void
    {
        $type = new NodeType();
        $this->assertSame('', $type->getName());
    }

    // -----------------------------------------------------------------
    // Description
    // -----------------------------------------------------------------

    public function testGetDescriptionDefault(): void
    {
        $type = new NodeType();
        $this->assertSame('', $type->getDescription());
    }

    public function testGetDescriptionViaConstructor(): void
    {
        $type = new NodeType(['description' => 'A blog article.']);
        $this->assertSame('A blog article.', $type->getDescription());
    }

    public function testSetDescription(): void
    {
        $type = new NodeType();
        $type->setDescription('A landing page.');
        $this->assertSame('A landing page.', $type->getDescription());
    }

    public function testSetDescriptionReturnsSelf(): void
    {
        $type = new NodeType();
        $this->assertSame($type, $type->setDescription('test'));
    }

    // -----------------------------------------------------------------
    // New revision
    // -----------------------------------------------------------------

    public function testIsNewRevisionDefaultTrue(): void
    {
        // Opt-OUT semantics (CW-v1 design decision 1, Drupal parity): a node
        // type that never says anything about new_revision creates a new
        // revision per save; `new_revision: false` is the explicit opt-out.
        $type = new NodeType();
        $this->assertTrue($type->isNewRevision());
    }

    public function testIsNewRevisionViaConstructor(): void
    {
        $type = new NodeType(['new_revision' => false]);
        $this->assertFalse($type->isNewRevision());
    }

    public function testSetNewRevision(): void
    {
        $type = new NodeType();
        $type->setNewRevision(true);
        $this->assertTrue($type->isNewRevision());

        $type->setNewRevision(false);
        $this->assertFalse($type->isNewRevision());
    }

    public function testSetNewRevisionReturnsSelf(): void
    {
        $type = new NodeType();
        $this->assertSame($type, $type->setNewRevision(true));
    }

    // -----------------------------------------------------------------
    // Display submitted
    // -----------------------------------------------------------------

    public function testGetDisplaySubmittedDefaultTrue(): void
    {
        $type = new NodeType();
        $this->assertTrue($type->getDisplaySubmitted());
    }

    public function testGetDisplaySubmittedViaConstructor(): void
    {
        $type = new NodeType(['display_submitted' => false]);
        $this->assertFalse($type->getDisplaySubmitted());
    }

    public function testSetDisplaySubmitted(): void
    {
        $type = new NodeType();
        $type->setDisplaySubmitted(false);
        $this->assertFalse($type->getDisplaySubmitted());

        $type->setDisplaySubmitted(true);
        $this->assertTrue($type->getDisplaySubmitted());
    }

    public function testSetDisplaySubmittedReturnsSelf(): void
    {
        $type = new NodeType();
        $this->assertSame($type, $type->setDisplaySubmitted(false));
    }

    // -----------------------------------------------------------------
    // Status (inherited from ConfigEntityBase)
    // -----------------------------------------------------------------

    public function testStatusDefaultTrue(): void
    {
        $type = new NodeType();
        $this->assertTrue($type->status());
    }

    public function testDisable(): void
    {
        $type = new NodeType();
        $type->disable();
        $this->assertFalse($type->status());
    }

    public function testEnable(): void
    {
        $type = new NodeType(['status' => false]);
        $this->assertFalse($type->status());

        $type->enable();
        $this->assertTrue($type->status());
    }

    // -----------------------------------------------------------------
    // toConfig (inherited from ConfigEntityBase)
    // -----------------------------------------------------------------

    public function testToConfigContainsAllValues(): void
    {
        $type = new NodeType([
            'type' => 'article',
            'name' => 'Article',
            'description' => 'Blog articles.',
            'new_revision' => true,
            'display_submitted' => true,
        ]);

        $config = $type->toConfig();

        $this->assertSame('article', $config['type']);
        $this->assertSame('Article', $config['name']);
        $this->assertSame('Blog articles.', $config['description']);
        $this->assertTrue($config['new_revision']);
        $this->assertTrue($config['display_submitted']);
        $this->assertTrue($config['status']);
        $this->assertArrayNotHasKey('uuid', $config);
    }

    public function testToConfigIncludesStatus(): void
    {
        $type = new NodeType(['type' => 'page']);
        $type->disable();

        $config = $type->toConfig();
        $this->assertFalse($config['status']);
    }

    // -----------------------------------------------------------------
    // Bundle (config entities default to entity type id)
    // -----------------------------------------------------------------

    public function testBundleDefaultsToEntityTypeId(): void
    {
        $type = new NodeType(['type' => 'article']);
        $this->assertSame('node_type', $type->bundle());
    }
}
