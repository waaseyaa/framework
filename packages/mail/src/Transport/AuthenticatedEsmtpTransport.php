<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/** Require authenticated submission; Symfony also supports unauthenticated relays. */
final class AuthenticatedEsmtpTransport extends EsmtpTransport
{
    protected function doSend(SentMessage $message): void
    {
        $this->start();
        if (!array_key_exists('AUTH', $this->getCapabilities())) {
            throw new TransportException('Authenticated SMTP submission is required.');
        }
        parent::doSend($message);
    }
}
