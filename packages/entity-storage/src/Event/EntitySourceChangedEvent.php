<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Event;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Transactional notification for projections of the current entity source.
 *
 * Runs after source writes and before commit. Subscriber writes must use the
 * supplied connection, and exceptions abort the mutation. No network work
 * belongs here. History-only revisions do not constitute a source change.
 * @api
 */
final readonly class EntitySourceChangedEvent
{
    public function __construct(
        public string $entityTypeId,
        public string $entityId,
        public DatabaseInterface $database,
    ) {}
}
