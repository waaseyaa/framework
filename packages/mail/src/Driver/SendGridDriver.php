<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Driver;

use SendGrid;
use SendGrid\Mail\Mail;
use Waaseyaa\Mail\MailDriverInterface;
use Waaseyaa\Mail\MailMessage;

final class SendGridDriver implements MailDriverInterface
{
    private SendGrid $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
        $this->client = new SendGrid($this->apiKey);
    }

    public function send(MailMessage $message): int
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Mail driver is not configured.');
        }

        $email = new Mail();
        $email->setFrom($message->from ?: $this->fromAddress, $message->fromName ?: $this->fromName);
        $email->setSubject($message->subject);
        $email->addTo($message->to);

        if ($message->body !== '') {
            $email->addContent('text/plain', $message->body);
        }

        if ($message->htmlBody !== '') {
            $email->addContent('text/html', $message->htmlBody);
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

        return $statusCode;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->fromAddress !== '';
    }
}
