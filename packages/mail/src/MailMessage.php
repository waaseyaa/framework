<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

final class MailMessage
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $htmlBody = '',
        public readonly string $fromName = '',
    ) {}
}
