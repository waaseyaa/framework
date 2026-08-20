<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Event;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\SaveContext;

/**
 * @api
 *
 * Dispatched before any backend write in a save operation.
 *
 * Subscribers may throw {@see AbortOperationException} to halt the save.
 * No backend writes occur after an abort; no {@see AfterSaveEvent} fires.
 *
 * @see AfterSaveEvent
 * @see AbortOperationException
 */
final class BeforeSaveEvent implements EntityLifecycleEventInterface
{
    public function __construct(
        private readonly EntityInterface $entityValue,
        private readonly SaveContext $saveContextValue,
        private readonly bool $newRevision,
        private readonly ?EntityInterface $originalEntityValue = null,
    ) {}

    public function entity(): EntityInterface
    {
        return $this->entityValue;
    }

    /**
     * The save context carrying flags for this operation (e.g. withoutNewRevision).
     */
    public function saveContext(): SaveContext
    {
        return $this->saveContextValue;
    }

    /**
     * Whether this save will create a new revision.
     */
    public function isNewRevision(): bool
    {
        return $this->newRevision;
    }

    /** The stored entity before this save, or null for a create. @api */
    public function originalEntity(): ?EntityInterface
    {
        return $this->originalEntityValue;
    }
}
