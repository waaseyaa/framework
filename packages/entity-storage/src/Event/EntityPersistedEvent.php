<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Event;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Required-invariant hook after persistence and entity hooks, before commit.
 *
 * Listeners may refuse by throwing. Related writes must use this transaction's
 * connection. This event is immediate even in saveMany(); POST_SAVE remains a
 * notification after the outermost commit. A null database does not promise
 * transactional rollback for an in-memory storage driver.
 *
 * @api
 */
final readonly class EntityPersistedEvent
{
    public function __construct(
        public EntityInterface $entity,
        public bool $isNew,
        public ?DatabaseInterface $database,
    ) {}
}
