<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;

#[CoversClass(Node::class)]
final class NodeTest extends TestCase
{
    private AccountFieldReadScope $fieldReadScope;

    protected function setUp(): void
    {
        $this->fieldReadScope = new AccountFieldReadScope();
        $handler = new EntityAccessHandler([new NodeAccessPolicy()]);
        EntityReadRuntime::installGuard(new FieldReadGuard($this->fieldReadScope, $handler->checkProtectedFieldRead(...)));
    }

    private function readProtected(callable $callback): mixed
    {
        return $this->fieldReadScope->run(
            new AuthorizationPrincipal(1, true, [], ['administer nodes'], 'node-test'),
            $callback,
        );
    }

    // -----------------------------------------------------------------
    // Construction and entity basics
    // -----------------------------------------------------------------

    public function testExtendsContentEntityBase(): void
    {
        $node = new Node();
        $this->assertInstanceOf(ContentEntityBase::class, $node);
    }

    public function testEntityTypeId(): void
    {
        $node = new Node();
        $this->assertSame('node', $node->getEntityTypeId());
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(Node::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testNewNodeIsNew(): void
    {
        $node = new Node();
        $this->assertTrue($node->isNew());
    }

    public function testNodeWithNidIsNotNew(): void
    {
        $node = new Node(['nid' => 42]);
        $this->assertSame(42, $node->id());
        $this->assertFalse($node->isNew());
    }

    public function testAutoGeneratesUuid(): void
    {
        $node = new Node();
        $uuid = $node->uuid();
        $this->assertNotEmpty($uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    public function testExplicitUuidIsPreserved(): void
    {
        $node = new Node(['uuid' => 'custom-uuid-5678']);
        $this->assertSame('custom-uuid-5678', $node->uuid());
    }

    public function testIdReturnsNullWhenNotSet(): void
    {
        $node = new Node();
        $this->assertNull($node->id());
    }

    // -----------------------------------------------------------------
    // Title
    // -----------------------------------------------------------------

    public function testGetTitleDefault(): void
    {
        $node = new Node();
        $this->assertSame('', $node->getTitle());
    }

    public function testGetTitleViaConstructor(): void
    {
        $node = new Node(['title' => 'Hello World']);
        $this->assertSame('Hello World', $node->getTitle());
    }

    public function testSetTitle(): void
    {
        $node = new Node();
        $node->setTitle('My First Post');
        $this->assertSame('My First Post', $node->getTitle());
    }

    public function testSetTitleReturnsSelf(): void
    {
        $node = new Node();
        $this->assertSame($node, $node->setTitle('Test'));
    }

    public function testLabelReturnsTitle(): void
    {
        $node = new Node(['title' => 'A Title']);
        $this->assertSame('A Title', $node->label());
    }

    // -----------------------------------------------------------------
    // Type (bundle)
    // -----------------------------------------------------------------

    public function testGetType(): void
    {
        $node = new Node(['type' => 'article']);
        $this->assertSame('article', $node->getType());
    }

    public function testGetTypeReturnsBundle(): void
    {
        $node = new Node(['type' => 'page']);
        $this->assertSame($node->bundle(), $node->getType());
    }

    public function testBundleDefaultsToEntityTypeId(): void
    {
        $node = new Node();
        $this->assertSame('node', $node->bundle());
    }

    // -----------------------------------------------------------------
    // Author
    // -----------------------------------------------------------------

    public function testGetAuthorIdDefault(): void
    {
        $node = new Node();
        $this->assertSame(0, $node->getAuthorId());
    }

    public function testGetAuthorIdViaConstructor(): void
    {
        $node = new Node(['uid' => 7]);
        $this->assertSame(7, $this->readProtected($node->getAuthorId(...)));
    }

    public function testSetAuthorId(): void
    {
        $node = new Node();
        $node->setAuthorId(42);
        $this->assertSame(42, $this->readProtected($node->getAuthorId(...)));
    }

    public function testSetAuthorIdReturnsSelf(): void
    {
        $node = new Node();
        $this->assertSame($node, $node->setAuthorId(1));
    }

    // -----------------------------------------------------------------
    // Published status
    // -----------------------------------------------------------------

    public function testNewNodeDefaultsToUnpublished(): void
    {
        $node = new Node();
        $this->assertFalse($this->readProtected($node->isPublished(...)));
    }

    public function testIsPublishedViaConstructor(): void
    {
        $node = new Node(['status' => 0]);
        $this->assertFalse($this->readProtected($node->isPublished(...)));
    }

    public function testSetPublished(): void
    {
        $node = new Node();
        $node->setPublished(false);
        $this->assertFalse($this->readProtected($node->isPublished(...)));

        $node->setPublished(true);
        $this->assertTrue($this->readProtected($node->isPublished(...)));
    }

    public function testSetPublishedReturnsSelf(): void
    {
        $node = new Node();
        $this->assertSame($node, $node->setPublished(true));
    }

    // -----------------------------------------------------------------
    // Promoted
    // -----------------------------------------------------------------

    public function testIsPromotedDefaultFalse(): void
    {
        $node = new Node();
        $this->assertFalse($node->isPromoted());
    }

    public function testIsPromotedViaConstructor(): void
    {
        $node = new Node(['promote' => 1]);
        $this->assertTrue($node->isPromoted());
    }

    public function testSetPromoted(): void
    {
        $node = new Node();
        $node->setPromoted(true);
        $this->assertTrue($node->isPromoted());

        $node->setPromoted(false);
        $this->assertFalse($node->isPromoted());
    }

    public function testSetPromotedReturnsSelf(): void
    {
        $node = new Node();
        $this->assertSame($node, $node->setPromoted(true));
    }

    // -----------------------------------------------------------------
    // Sticky
    // -----------------------------------------------------------------

    public function testIsStickyDefaultFalse(): void
    {
        $node = new Node();
        $this->assertFalse($node->isSticky());
    }

    public function testIsStickyViaConstructor(): void
    {
        $node = new Node(['sticky' => 1]);
        $this->assertTrue($node->isSticky());
    }

    public function testSetSticky(): void
    {
        $node = new Node();
        $node->setSticky(true);
        $this->assertTrue($node->isSticky());

        $node->setSticky(false);
        $this->assertFalse($node->isSticky());
    }

    public function testSetStickyReturnsSelf(): void
    {
        $node = new Node();
        $this->assertSame($node, $node->setSticky(true));
    }

    // -----------------------------------------------------------------
    // Timestamps
    // -----------------------------------------------------------------

    public function testGetCreatedTimeDefault(): void
    {
        $node = new Node();
        $this->assertSame(0, $node->getCreatedTime());
    }

    public function testGetCreatedTimeViaConstructor(): void
    {
        $node = new Node(['created' => 1700000000]);
        $this->assertSame(1700000000, $node->getCreatedTime());
    }

    public function testSetCreatedTime(): void
    {
        $node = new Node();
        $node->setCreatedTime(1700000001);
        $this->assertSame(1700000001, $node->getCreatedTime());
    }

    public function testSetCreatedTimeReturnsSelf(): void
    {
        $node = new Node();
        $this->assertSame($node, $node->setCreatedTime(0));
    }

    public function testGetChangedTimeDefault(): void
    {
        $node = new Node();
        $this->assertSame(0, $node->getChangedTime());
    }

    public function testGetChangedTimeViaConstructor(): void
    {
        $node = new Node(['changed' => 1700000050]);
        $this->assertSame(1700000050, $node->getChangedTime());
    }

    public function testSetChangedTime(): void
    {
        $node = new Node();
        $node->setChangedTime(1700000099);
        $this->assertSame(1700000099, $node->getChangedTime());
    }

    public function testSetChangedTimeReturnsSelf(): void
    {
        $node = new Node();
        $this->assertSame($node, $node->setChangedTime(0));
    }

    // -----------------------------------------------------------------
    // toArray
    // -----------------------------------------------------------------

    public function testToArrayContainsAllValues(): void
    {
        $node = new Node([
            'nid' => 10,
            'type' => 'article',
            'title' => 'Test Article',
            'uid' => 3,
            'status' => 1,
            'promote' => 1,
            'sticky' => 0,
            'created' => 1700000000,
            'changed' => 1700000050,
        ]);

        $array = $this->readProtected($node->toArray(...));
        $this->assertSame(10, $array['nid']);
        $this->assertSame('article', $array['type']);
        $this->assertSame('Test Article', $array['title']);
        $this->assertSame(3, $array['uid']);
        $this->assertSame(1, $array['status']);
        $this->assertSame(1, $array['promote']);
        $this->assertSame(0, $array['sticky']);
        $this->assertSame(1700000000, $array['created']);
        $this->assertSame(1700000050, $array['changed']);
        $this->assertArrayHasKey('uuid', $array);
    }

    // -----------------------------------------------------------------
    // Full construction scenario
    // -----------------------------------------------------------------

    public function testFullNodeCreation(): void
    {
        $node = new Node([
            'nid' => 1,
            'type' => 'page',
            'title' => 'About Us',
            'uid' => 5,
            'status' => 1,
            'promote' => 0,
            'sticky' => 1,
            'created' => 1600000000,
            'changed' => 1600000100,
        ]);

        $this->assertSame(1, $node->id());
        $this->assertSame('page', $node->getType());
        $this->assertSame('About Us', $node->getTitle());
        $this->assertSame(5, $this->readProtected($node->getAuthorId(...)));
        $this->assertTrue($this->readProtected($node->isPublished(...)));
        $this->assertFalse($node->isPromoted());
        $this->assertTrue($node->isSticky());
        $this->assertSame(1600000000, $node->getCreatedTime());
        $this->assertSame(1600000100, $node->getChangedTime());
        $this->assertFalse($node->isNew());
    }
}
