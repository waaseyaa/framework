<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\EntityStorage\Hydration\EntityInstantiator;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Node\Listener\NodeRevisionDefaultListener;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\Tests\Unit\Fixtures\StubNodeTypeEntityTypeManager;

/**
 * CW-v1 WP-2 Task 2.3 — pins {@see NodeRevisionDefaultListener} in isolation:
 * it reads the bundle's `NodeType::isNewRevision()` and forwards it onto the
 * node via `setNewRevision()`, but ONLY when nothing has already made an
 * explicit per-save decision (the legacy `RevisionableInterface` contract
 * reserves `null` for "undecided" — see `RevisionableEntityTrait`).
 */
#[CoversClass(NodeRevisionDefaultListener::class)]
final class NodeRevisionDefaultListenerTest extends TestCase
{
    #[Test]
    public function forwards_the_bundle_default_of_false_onto_the_node(): void
    {
        $node = new Node(['type' => 'note', 'title' => 'T', 'slug' => 't']);
        $this->assertNull($node->isNewRevision(), 'Precondition: nothing decided yet.');

        $nodeType = new NodeType(['type' => 'note', 'name' => 'Note', 'new_revision' => false]);
        $listener = new NodeRevisionDefaultListener(new StubNodeTypeEntityTypeManager(['note' => $nodeType]));

        $listener(new EntityEvent($node));

        $this->assertFalse($node->isNewRevision());
    }

    #[Test]
    public function forwards_the_default_from_a_production_sealed_node_type(): void
    {
        $provider = new NodeServiceProvider();
        $provider->register();
        $definition = $provider->getEntityTypes()[1];
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('node_type', $definition->getFieldDefinitions());
        $nodeType = new EntityInstantiator($definition, $registry)->instantiate(NodeType::class, [
            'type' => 'note',
            'name' => 'Note',
            'description' => '',
            'new_revision' => false,
            'display_submitted' => true,
            'status' => true,
        ]);
        self::assertInstanceOf(NodeType::class, $nodeType);
        $node = new Node(['type' => 'note', 'title' => 'T', 'slug' => 't']);
        $listener = new NodeRevisionDefaultListener(new StubNodeTypeEntityTypeManager(['note' => $nodeType]));

        $listener(new EntityEvent($node));

        self::assertFalse($node->isNewRevision());
    }

    #[Test]
    public function forwards_the_bundle_default_of_true_onto_the_node(): void
    {
        $node = new Node(['type' => 'article', 'title' => 'T', 'slug' => 't']);
        $nodeType = new NodeType(['type' => 'article', 'name' => 'Article', 'new_revision' => true]);
        $listener = new NodeRevisionDefaultListener(new StubNodeTypeEntityTypeManager(['article' => $nodeType]));

        $listener(new EntityEvent($node));

        $this->assertTrue($node->isNewRevision());
    }

    #[Test]
    public function a_node_type_created_without_a_new_revision_key_forwards_true(): void
    {
        // The realistic default case (CW-v1 design decision 1, opt-OUT
        // semantics): a normal materialized NodeType row that never mentions
        // new_revision must leave revisioning ON — forwarding `false` here
        // would silently disable revisioning for every standard bundle.
        $node = new Node(['type' => 'article', 'title' => 'T', 'slug' => 't']);
        $nodeType = new NodeType(['type' => 'article', 'name' => 'Article']);
        $listener = new NodeRevisionDefaultListener(new StubNodeTypeEntityTypeManager(['article' => $nodeType]));

        $listener(new EntityEvent($node));

        $this->assertTrue($node->isNewRevision());
    }

    #[Test]
    public function leaves_the_decision_unset_when_the_bundle_has_no_node_type_row(): void
    {
        $node = new Node(['type' => 'orphan_bundle', 'title' => 'T', 'slug' => 't']);
        $listener = new NodeRevisionDefaultListener(new StubNodeTypeEntityTypeManager(['orphan_bundle' => null]));

        $listener(new EntityEvent($node));

        $this->assertNull(
            $node->isNewRevision(),
            'A missing/unloadable NodeType must not crash and must leave the decision to the entity-type default.',
        );
    }

    #[Test]
    public function leaves_the_decision_unset_when_node_type_is_not_registered_at_all(): void
    {
        $node = new Node(['type' => 'article', 'title' => 'T', 'slug' => 't']);
        $listener = new NodeRevisionDefaultListener(new StubNodeTypeEntityTypeManager([], hasNodeTypeDefinition: false));

        $listener(new EntityEvent($node));

        $this->assertNull($node->isNewRevision());
    }

    #[Test]
    public function does_not_override_an_explicit_true_decision_made_earlier_in_the_save(): void
    {
        $node = new Node(['type' => 'note', 'title' => 'T', 'slug' => 't']);
        $node->setNewRevision(true); // Any earlier actor's explicit per-save decision.

        $nodeType = new NodeType(['type' => 'note', 'name' => 'Note', 'new_revision' => false]);
        $listener = new NodeRevisionDefaultListener(new StubNodeTypeEntityTypeManager(['note' => $nodeType]));

        $listener(new EntityEvent($node));

        $this->assertTrue($node->isNewRevision(), 'An earlier explicit decision must survive the bundle-default listener.');
    }

    #[Test]
    public function does_not_override_an_explicit_false_decision_made_earlier_in_the_save(): void
    {
        $node = new Node(['type' => 'article', 'title' => 'T', 'slug' => 't']);
        $node->setNewRevision(false);

        $nodeType = new NodeType(['type' => 'article', 'name' => 'Article', 'new_revision' => true]);
        $listener = new NodeRevisionDefaultListener(new StubNodeTypeEntityTypeManager(['article' => $nodeType]));

        $listener(new EntityEvent($node));

        $this->assertFalse($node->isNewRevision(), 'An earlier explicit decision must survive the bundle-default listener.');
    }

    #[Test]
    public function ignores_entities_that_are_not_nodes(): void
    {
        // A non-Node entity must never trigger a node_type lookup — the stub
        // throws OutOfBoundsException for any unconfigured find(), so a
        // wrongly-triggered lookup fails this test loudly.
        $entity = new class (['bundle' => 'irrelevant']) extends \Waaseyaa\Entity\ContentEntityBase {
            public function __construct(array $values = [])
            {
                parent::__construct($values, 'not_a_node', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'bundle', 'bundle' => 'bundle']);
            }
        };

        $listener = new NodeRevisionDefaultListener(new StubNodeTypeEntityTypeManager());

        $listener(new EntityEvent($entity));

        $this->addToAssertionCount(1);
    }
}
