<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Repository for Attachment entities.
 *
 * All entity CRUD is delegated to the injected EntityRepositoryInterface.
 * The only exception is {@see setActive()}, which issues two UPDATE statements
 * in a single transaction to atomically enforce the at-most-one-active
 * invariant (see data-model.md § 1 and research.md Q6).
 *
 * @api
 */
final class AttachmentRepository
{
    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository,
        private readonly DatabaseInterface $database,
    ) {}

    /**
     * Returns all attachments for the given parent entity, ordered by id ASC.
     *
     * @return list<Attachment>
     */
    public function listFor(string $parentEntityType, string $parentId): array
    {
        /** @var list<Attachment> */
        return $this->entityRepository->findBy(
            ['parent_entity_type' => $parentEntityType, 'parent_entity_id' => $parentId],
            ['id' => 'ASC'],
        );
    }

    /**
     * Returns the single active attachment for the given parent entity, or null.
     */
    public function getActive(string $parentEntityType, string $parentId): ?Attachment
    {
        /** @var list<Attachment> $results */
        $results = $this->entityRepository->findBy(
            [
                'parent_entity_type' => $parentEntityType,
                'parent_entity_id' => $parentId,
                'is_active' => 1,
            ],
            null,
            1,
        );

        return $results[0] ?? null;
    }

    /**
     * Persists an Attachment entity via the entity repository.
     */
    public function save(Attachment $attachment): void
    {
        $this->entityRepository->save($attachment);
    }

    /**
     * Removes an Attachment entity by its ID.
     *
     * No-op if the attachment does not exist.
     */
    public function delete(string $attachmentId): void
    {
        $entity = $this->entityRepository->find($attachmentId);
        if ($entity instanceof Attachment) {
            $this->entityRepository->delete($entity);
        }
    }

    /**
     * Atomically marks the given attachment as active and clears all sibling
     * attachments for the same parent entity.
     *
     * Issues two UPDATE statements in a single database transaction:
     *   1. SET is_active = 0 on all siblings (same parent_entity_type + parent_entity_id).
     *   2. SET is_active = 1 on the target attachment.
     *
     * Entity events are NOT fired for deactivated siblings — intentional.
     *
     * @throws AttachmentNotFoundException If no attachment with $attachmentId exists.
     */
    public function setActive(string $attachmentId): void
    {
        $attachment = $this->entityRepository->find($attachmentId);
        if (!$attachment instanceof Attachment) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        $parentType = (string) $attachment->get('parent_entity_type');
        $parentId = (string) $attachment->get('parent_entity_id');

        $transaction = $this->database->transaction();
        try {
            // Clear active flag on all attachments for this parent.
            $this->database->update('attachment')
                ->fields(['is_active' => 0])
                ->condition('parent_entity_type', $parentType)
                ->condition('parent_entity_id', $parentId)
                ->execute();

            // Set active flag on the target attachment.
            $this->database->update('attachment')
                ->fields(['is_active' => 1])
                ->condition('id', $attachmentId)
                ->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
