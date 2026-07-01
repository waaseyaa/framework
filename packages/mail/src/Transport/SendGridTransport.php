<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use SendGrid;
use SendGrid\Mail\Mail;
use Waaseyaa\Mail\Envelope;

/**
 * Send outbound mail via SendGrid Web API v3.
 */
final class SendGridTransport implements TransportInterface
{
    private SendGrid $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
        $this->client = new SendGrid($this->apiKey);
    }

    public function send(Envelope $envelope): void
    {
        if ($this->apiKey === '' || $this->fromAddress === '') {
            throw new \RuntimeException('SendGrid transport is not configured.');
        }

        $recipients = array_values(array_filter(
            array_map(static fn(string $r): string => trim($r), $envelope->to),
            static fn(string $r): bool => $r !== '',
        ));
        if ($recipients === []) {
            throw new \InvalidArgumentException('Cannot send email: no valid recipients provided.');
        }

        $email = new Mail();
        $from = $envelope->from !== '' ? $envelope->from : $this->fromAddress;
        $email->setFrom($from, $this->fromName);
        $email->setSubject($envelope->subject);

        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }

        if ($envelope->textBody !== '') {
            $email->addContent('text/plain', $envelope->textBody);
        }

        if ($envelope->htmlBody !== '') {
            $email->addContent('text/html', $envelope->htmlBody);
        }

        $response = $this->client->send($email);
        $statusCode = $response->statusCode();

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'SendGrid returned HTTP %d: %s',
                $statusCode,
                $response->body(),
            ));
        }
    }
}
