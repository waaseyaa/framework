<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

/** Requeues a previously verified failed payload without reconstructing its authority envelope. @api */
interface PersistentPayloadReplayInterface
{
    public function replaySignedPayload(string $queue, string $signedPayload): void;
}
