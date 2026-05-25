<?php

declare(strict_types=1);

namespace Waaseyaa\Api\MercureMonitor;

/**
 * Read contract for recent broadcast events.
 *
 * Implemented by `packages/foundation/src/Http/Inbound/EventStreamReadModel.php`.
 *
 * @api
 */
interface EventStreamReadModelInterface
{
    /**
     * @return list<BroadcastEventRow>
     */
    public function recentEvents(EventStreamFilter $filter, int $limit = 100): array;
}
