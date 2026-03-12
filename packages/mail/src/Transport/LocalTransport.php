<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Waaseyaa\Mail\Envelope;

final class LocalTransport implements TransportInterface
{
    public function __construct(
        private readonly string $logPath,
    ) {}

    public function send(Envelope $envelope): void
    {
        $entry = sprintf(
            "[%s] To: %s | From: %s | Subject: %s\n",
            date('Y-m-d H:i:s'),
            implode(', ', $envelope->to),
            $envelope->from,
            $envelope->subject,
        );

        file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}
