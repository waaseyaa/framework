<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Envelope;

/** Resolves fresh authority and confines it to exactly one handler callback. @api */
interface QueueAuthorityRuntimeInterface
{
    public function run(QueueEnvelopeV1 $envelope, \Closure $handler): mixed;
}
