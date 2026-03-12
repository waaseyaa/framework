<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Waaseyaa\Mail\Envelope;

final class ArrayTransport implements TransportInterface
{
    /** @var list<Envelope> */
    private array $sent = [];

    public function send(Envelope $envelope): void
    {
        $this->sent[] = $envelope;
    }

    /** @return list<Envelope> */
    public function getSent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
