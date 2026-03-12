<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

use Waaseyaa\Mail\Transport\TransportInterface;

final class Mailer implements MailerInterface
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $defaultFrom = '',
    ) {}

    public function send(Envelope $envelope): void
    {
        if ($envelope->from === '' && $this->defaultFrom !== '') {
            $envelope = new Envelope(
                to: $envelope->to,
                from: $this->defaultFrom,
                subject: $envelope->subject,
                textBody: $envelope->textBody,
                htmlBody: $envelope->htmlBody,
                headers: $envelope->headers,
            );
        }

        $this->transport->send($envelope);
    }
}
