<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Waaseyaa\Mail\Envelope;

interface TransportInterface
{
    public function send(Envelope $envelope): void;
}
