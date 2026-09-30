<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\EntityStorage\Event\EntitySourceChangedEvent;

/** @internal Advances source fencing inside the entity mutation, with no provider I/O. */
final readonly class EmbeddingSourceChangedListener
{
    public function __construct(
        private EmbeddingStorageInterface $storage,
        private EmbeddingExecutionGuardInterface $guard,
    ) {}

    public function onSourceChanged(EntitySourceChangedEvent $event): void
    {
        $this->guard->sourceChanged($event->entityTypeId, $event->entityId, $event->database, $this->storage);
    }
}
