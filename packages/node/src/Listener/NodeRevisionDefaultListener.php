<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Listener;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
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
 * `$node->isNewRevision() === null`. Any earlier actor that explicitly set a
 * per-save decision via `setNewRevision()` — a caller before `save()`, or an
 * earlier PRE_SAVE listener — has that decision left untouched regardless of
 * listener registration order, because this listener never overwrites a
 * non-null value.
 *
 * A missing or unloadable `NodeType` (bundle config not yet created, or the
 * `node_type` entity type not registered in this boot) is NOT an error: the
 * decision is simply left `null`, so `shouldCreateRevision()` falls back to
 * the node entity type's `revisionDefault` (`true`).
 */
final class NodeRevisionDefaultListener
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

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
        } catch (\Throwable $e) {
            // Best-effort side effect: an unloadable NodeType must not crash
            // the save — fall back to the entity-type default, but say so.
            $this->logger->debug('node.revision_default_lookup_failed', [
                'bundle' => $bundle,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return $nodeType instanceof NodeType ? $nodeType : null;
    }
}
