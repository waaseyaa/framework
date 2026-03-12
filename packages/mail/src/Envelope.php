<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

final class Envelope
{
    /**
     * @param list<string> $to
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly array $to,
        public readonly string $from,
        public readonly string $subject,
        public readonly string $textBody = '',
        public readonly string $htmlBody = '',
        public readonly array $headers = [],
    ) {}
}
