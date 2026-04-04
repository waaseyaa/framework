<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

/**
 * @internal
 */
interface MailDriverInterface
{
    /**
     * Send an email message.
     *
     * @return int HTTP status code (202 = accepted for SendGrid)
     * @throws \RuntimeException on failure
     */
    public function send(MailMessage $message): int;

    public function isConfigured(): bool;
}
