<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Envelope;

/** Dormant default: envelope metadata propagates, but no read authority is installed. @api */
final readonly class NoAuthorityQueueRuntime implements QueueAuthorityRuntimeInterface
{
    public function run(QueueEnvelopeV1 $envelope, \Closure $handler): mixed
    {
        return $handler();
    }
}
