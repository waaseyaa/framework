<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Workflows\WorkflowVisibility;

/**
 * CW-v1 option-1 (#1920 PR-2, design §3.3): re-sources from the SERVED
 * content (`repository->find()`) rather than trusting the triggering
 * event's own entity/revision object. This closes two bugs in one fix:
 *
 * - A forward-draft save must not index its own unreviewed tip content —
 *   `onPostSave()`'s in-memory `$event->entity` IS that tip under
 *   discipline.
 * - Editing a published node into a forward draft must not delete the
 *   still-served embedding (the previously documented WP-2 de-index bug,
 *   `docs/specs/content-workflow.md` "Visibility (read side)") — the tip's
 *   `workflow_state` says 'draft' even though the published pointer (and
 *   `status`) still serve it live; re-sourcing via `find()` returns the
 *   served (base-row) content, whose `workflow_state` and `status` agree,
 *   so {@see WorkflowVisibility::isNodePublicForEntity()} evaluates
 *   correctly WITHOUT the previously-sketched precedence flip (the spec's
 *   "Visibility (read side)" follow-up is retired by this fix — see
 *   {@see \Waaseyaa\Workflows\WorkflowVisibility}, whose own precedence and
 *   pinned tests stay untouched).
 *
 * Additionally subscribes to {@see RevisionPointerMovedEvent} and the
 * legacy `EntityEvents::REVISION_REVERTED` (mirrors
 * `Waaseyaa\Cache\Listener\EntityCacheSubscriber`'s pattern): a standalone
 * pointer move (rollback/revert/promote with no accompanying `save()`) now
 * changes served content with no POST_SAVE of its own — without these two
 * subscriptions, a live-view rollback would leave a stale embedding until
 * the next ordinary edit.
 *
 * When no {@see EntityTypeManagerInterface} is wired (degraded/standalone
 * construction — e.g. `SemanticIndexWarmer`'s inline instantiation, or a
 * unit test), `onPostSave()`/`onRevisionReverted()` fall back to the
 * triggering event's own entity object (pre-option-1 behavior, unchanged);
 * `onRevisionPointerMoved()` (which carries no entity object at all) then
 * has nothing to re-source and no-ops.
 */
final class EntityEmbeddingListener
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?QueueInterface $queue = null,
        private readonly ?EmbeddingStorageInterface $storage = null,
        private readonly ?EmbeddingProviderInterface $embeddingProvider = null,
        private readonly WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
        ?LoggerInterface $logger = null,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function onPostSave(EntityEvent $event): void
    {
        $this->reindex($event->entity->getEntityTypeId(), $event->entity->id(), $event->entity);
    }

    /**
     * @api
     */
    public function onRevisionPointerMoved(RevisionPointerMovedEvent $event): void
    {
        $this->reindex($event->entityTypeId, $event->entityId, null);
    }

    /**
     * @api
     */
    public function onRevisionReverted(EntityEvent $event): void
    {
        $this->reindex($event->entity->getEntityTypeId(), $event->entity->id(), $event->entity);
    }

    /**
     * The re-sourced re-index/de-index core (CW-v1 option-1 §3.3). `$fallbackEntity`
     * is used ONLY when no `entityTypeManager` is wired — see class docblock.
     */
    private function reindex(string $entityType, int|string|null $entityId, ?EntityInterface $fallbackEntity): void
    {
        if ($entityId === null || $entityId === '') {
            return;
        }

        $entityIdString = (string) $entityId;

        if ($this->entityTypeManager !== null) {
            $entity = $this->entityTypeManager->getRepository($entityType)->find($entityIdString);
        } elseif ($fallbackEntity !== null) {
            $entity = $fallbackEntity;
        } else {
            // No entityTypeManager AND no fallback entity (a pointer-move
            // event with no wired re-source path) — nothing safe to do.
            return;
        }

        if (!$this->isIndexable($entityType, $entity)) {
            if ($this->storage !== null) {
                $this->storage->delete($entityType, $entityIdString);
            }

            return;
        }

        \assert($entity instanceof EntityInterface);

        if ($this->storage !== null && $this->embeddingProvider !== null) {
            try {
                $vector = $this->embeddingProvider->embed($this->buildEmbeddingText($entity));
                $this->storage->store($entityType, $entityIdString, $vector);
            } catch (\Throwable $exception) {
                $this->logger->error(sprintf(
                    'Embedding update failed for %s:%s: %s',
                    $entityType,
                    $entityIdString,
                    $exception->getMessage(),
                ));
            }
        }

        if ($this->queue === null) {
            return;
        }

        $this->queue->dispatch(new GenericMessage(
            type: 'ai_vector.embed_entity',
            payload: [
                'entity_type' => $entityType,
                'entity_id' => $entityIdString,
                'langcode' => $entity->language(),
            ],
        ));
    }

    private function isIndexable(string $entityType, ?EntityInterface $entity): bool
    {
        if ($entity === null) {
            // No served content exists (deleted concurrently, or a
            // pointer-move event whose target row is gone) — not indexable.
            return false;
        }

        if ($entityType !== 'node') {
            return true;
        }

        return $this->workflowVisibility->isNodePublicForEntity($entity);
    }

    private function buildEmbeddingText(EntityInterface $entity): string
    {
        $values = EntityValues::toCastAwareMap($entity);
        $parts = [];

        foreach (['title', 'name', 'body', 'description'] as $field) {
            if (!\array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            if (\is_string($value) || \is_int($value) || \is_float($value)) {
                $trimmed = trim((string) $value);
                if ($trimmed !== '') {
                    $parts[] = $trimmed;
                }
            }
        }

        $label = trim($entity->label());
        if ($label !== '') {
            array_unshift($parts, $label);
        }

        $parts = array_values(array_unique($parts));
        if ($parts === []) {
            return sprintf(
                '%s %s',
                $entity->getEntityTypeId(),
                (string) ($entity->id() ?? ''),
            );
        }

        return implode("\n\n", $parts);
    }

}
