<?php

declare(strict_types=1);

namespace Waaseyaa\Api\MercureMonitor;

/**
 * Read contract for current SSE subscribers.
 *
 * Returns active subscriber rows from the process-shared subscribers.json
 * written by `BroadcastRouter` on connect/disconnect.
 *
 * No session tokens, IPs, or User-Agent strings are exposed (NFR-004).
 *
 * Implemented by `packages/foundation/src/Http/Inbound/SubscriberObserver.php`.
 *
 * @api
 */
interface SubscriberObserverInterface
{
    /**
     * @return list<SubscriberRow>
     */
    public function currentSubscribers(): array;
}
