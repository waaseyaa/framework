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

        // Suppress the PHP I/O warning — we detect the failure via the false return
        // and re-surface it as a typed RuntimeException below, so the warning is noise.
        $written = @file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException(sprintf('Failed to write mail log entry to "%s".', $this->logPath));
        }
    }
}
