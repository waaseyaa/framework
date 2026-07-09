<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Listener;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeType;

/**
 * Wires the per-bundle `NodeType::isNewRevision()` knob into the save path
 * (CW-v1 WP-2 Task 2.3, docs/specs/content-workflow.md).
 *
 * Node's entity type registers `revisionable: true, revisionDefault: true`
 * (Task 2.1), so `EntityRepository::shouldCreateRevision()` creates a new
 * revision on every ordinary save UNLESS the entity itself carries a
 * non-null per-entity override (the legacy `RevisionableInterface` contract:
 * `isNewRevision(): ?bool`, `null` = "no explicit decision, use the
 * entity-type default"). `NodeType::isNewRevision()` was, until this
 * listener, a knob wired to nothing — every bundle behaved identically
 * regardless of its `new_revision` setting.
 *
 * Registered by {@see \Waaseyaa\Node\NodeServiceProvider::boot()} on
 * `EntityEvents::PRE_SAVE` for every entity save (all entity types share one
 * PRE_SAVE event name; this listener filters to `Node` instances) — the
 * SAME event `EntityRepository::save()` dispatches BEFORE calling
 * `shouldCreateRevision()`, so a decision made here takes effect for that
 * save.
 *
 * Explicit-decision precedence: this listener only acts when
 * `$node->isNewRevision() === null`. An earlier PRE_SAVE actor (e.g.
 * `Waaseyaa\Workflows\Transition\TransitionService`, or a caller that calls
 * `setNewRevision()` directly before `save()`) may have already made an
 * explicit true/false decision — that decision is left untouched regardless
 * of listener registration order, because this listener never overwrites a
 * non-null value.
 *
 * A missing or unloadable `NodeType` (bundle config not yet created, or the
 * `node_type` entity type not registered in this boot) is NOT an error: the
 * decision is simply left `null`, so `shouldCreateRevision()` falls back to
 * the node entity type's `revisionDefault` (`true`).
 */
final class NodeRevisionDefaultListener
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function __invoke(EntityEvent $event): void
    {
        $entity = $event->entity;
        if (!$entity instanceof Node) {
            return;
        }

        // Caller override takes precedence (contract: null = undecided).
        if ($entity->isNewRevision() !== null) {
            return;
        }

        $nodeType = $this->loadNodeType($entity->getType());
        if ($nodeType === null) {
            return;
        }

        $entity->setNewRevision($nodeType->isNewRevision());
    }

    private function loadNodeType(string $bundle): ?NodeType
    {
        if ($bundle === '' || !$this->entityTypeManager->hasDefinition('node_type')) {
            return null;
        }

        try {
            $nodeType = $this->entityTypeManager->getRepository('node_type')->find($bundle);
        } catch (\Throwable) {
            return null;
        }

        return $nodeType instanceof NodeType ? $nodeType : null;
    }
}
