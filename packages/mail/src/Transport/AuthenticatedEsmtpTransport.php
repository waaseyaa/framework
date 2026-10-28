<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Require authenticated submission; Symfony also supports unauthenticated relays. */
final class AuthenticatedEsmtpTransport extends EsmtpTransport
{
    private bool $authenticated = false;

    public function executeCommand(string $command, array $codes): string
    {
        // SmtpTransport enters every new handshake here. EsmtpTransport owns
        // EHLO, STARTTLS and authenticators, including reconnects after NOOP.
        $greeting = $codes === [250] && str_starts_with($command, 'HELO ');
        if ($greeting) {
            $this->authenticated = false;
        }
        $response = parent::executeCommand($command, $codes);
        if ($codes === [235]) {
            $this->authenticated = true;
        }
        // Require this handshake's completed AUTH, not cached capabilities.
        // Symfony's EHLO-to-HELO fallback is not authenticated submission.
        if ($greeting && !$this->authenticated) {
            throw new TransportException('Authenticated SMTP submission is required.');
        }
        return $response;
    }
}
