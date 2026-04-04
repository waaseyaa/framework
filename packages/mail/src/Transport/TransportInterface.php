<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Waaseyaa\Mail\Envelope;

/**
 * @internal
 */
interface TransportInterface
{
    public function send(Envelope $envelope): void;
}
