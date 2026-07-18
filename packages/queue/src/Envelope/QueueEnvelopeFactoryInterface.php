<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Envelope;

/** Dispatch-bound authority envelope factory supplied by the composition root. @api */
interface QueueEnvelopeFactoryInterface
{
    public function wrap(object $message, string $serializedMessage): QueueEnvelopeV1;
}
