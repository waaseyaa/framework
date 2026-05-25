<?php

declare(strict_types=1);

namespace Waaseyaa\Api\MercureMonitor;

/**
 * Read contract for the Mercure broadcast channel inspector.
 *
 * Returns 24h per-channel statistics from the `_broadcast_log` table.
 * Implemented by `packages/foundation/src/Http/Inbound/ChannelInspector.php`.
 *
 * @api
 */
interface ChannelInspectorInterface
{
    /**
     * @return list<ChannelInspectorRow>
     */
    public function listChannels(): array;
}
