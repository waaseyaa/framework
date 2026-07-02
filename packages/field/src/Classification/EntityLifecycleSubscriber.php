<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Resolves and persists the effective classification label on every entity save.
 *
 * On PRE_SAVE:
 *   - Resolves the effective label via LabelInheritanceResolver.
 *   - Writes the resolved label + provenance columns back to the entity.
 *   - Detects label changes and dispatches a classification.change audit event
 *     via AuditWriterInterface (best-effort — never throws; see CLAUDE.md).
 *
 * Best-effort pattern: the entire handler body is wrapped in try-catch.
 * An audit-write failure must not crash the entity save. (DIR-004, CLAUDE.md
 * §Logging "best-effort side effects".)
 *
 * @api
 */
final class EntityLifecycleSubscriber implements EventSubscriberInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly LabelInheritanceResolver $resolver,
        private readonly AuditWriterInterface $auditWriter,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::PRE_SAVE->value => ['onPreSave', 0],
        ];
    }

    public function onPreSave(EntityEvent $event): void
    {
        try {
            $entity = $event->entity;

            // Read the currently-persisted label for change detection.
            $previousLabel = null;
            if ($event->originalEntity !== null) {
                $raw = $event->originalEntity->get('classification_label');
                $previousLabel = ($raw !== null && $raw !== '') ? (string) $raw : null;
            }

            // Resolve the effective classification decision.
            $decision = $this->resolver->resolve($entity, $previousLabel);

            // Skip the write-back entirely when there is nothing real to
            // record: the decision is all-null, no previous label needs
            // clearing, and the entity carries no stray classification value.
            // Now that this subscriber is live framework-wide (WP4
            // dead-subscriber sweep), unconditionally writing three NULL keys
            // would pollute EVERY entity's value bag — and thus the sql-blob
            // `_data` column — including entity types with zero classification
            // involvement (the common case). An all-null decision is still
            // written when it CLEARS something (a previous label, or a stale
            // in-memory classification value), so cleared labels never
            // silently survive in storage.
            $storage = $decision->toStorageArray();
            $decisionIsAllNull = $decision->label === null
                && $decision->inheritedFromUuid === null
                && $decision->overriddenAt === null;
            if ($decisionIsAllNull
                && $previousLabel === null
                && !$this->carriesClassificationValue($entity, array_keys($storage))
            ) {
                return;
            }

            // Write resolved values back to entity columns.
            foreach ($storage as $column => $value) {
                $entity->set($column, $value);
            }

            // Detect label change for audit.
            $newLabel = $decision->label;
            if ($newLabel === $previousLabel) {
                return; // No change — nothing to audit.
            }

            // Record a classification.change audit event (best-effort).
            $entityTypeId = $entity->getEntityTypeId();
            $entityUuid = $entity->uuid() !== '' ? $entity->uuid() : null;
            $entityId = (string) ($entity->id() ?? '');

            // Resolve account UID from the entity's `uid` field when available.
            $accountUid = 0;
            if ($entity instanceof \Waaseyaa\Entity\FieldableInterface) {
                $uid = $entity->get('uid');
                if ($uid !== null && $uid !== '') {
                    $accountUid = (int) $uid;
                }
            }

            $subjectUri = sprintf(
                '/entities/%s/%s',
                $entityTypeId,
                $entityUuid ?? $entityId,
            );

            $this->auditWriter->record(new AuditEventDescriptor(
                kind: AuditEventKind::ClassificationChange,
                accountUid: $accountUid,
                subjectUri: $subjectUri,
                outcome: 'allowed',
                severity: 'notice',
                entityTypeId: $entityTypeId,
                entityUuid: $entityUuid,
                attributes: [
                    'previous_label' => $previousLabel,
                    'new_label' => $newLabel,
                    'inherited' => $decision->inherited,
                    'inherited_from' => $decision->inheritedFromUuid,
                ],
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('classification.lifecycle_failed', [
                'listener' => self::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether the entity currently carries a non-empty value under any of the
     * classification storage keys (e.g. stale hydrated provenance whose
     * parent lost its label) — if so, an all-null decision must still be
     * written to clear it.
     *
     * @param list<string> $keys
     */
    private function carriesClassificationValue(EntityInterface $entity, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $entity->get($key);
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
