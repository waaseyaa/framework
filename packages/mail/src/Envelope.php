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
    ) {
        foreach ($to as $index => $address) {
            $this->assertNoCrlf("to[{$index}]", $address);
        }
        $this->assertNoCrlf('from', $from);
        $this->assertNoCrlf('subject', $subject);
        foreach ($headers as $name => $value) {
            $this->assertNoCrlf('header name', $name);
            $this->assertNoCrlf('header value', $value);
        }
    }

    /**
     * Rejects CR, LF, and NUL characters in header fields to prevent
     * email header injection and log injection attacks.
     */
    private function assertNoCrlf(string $field, string $value): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new \InvalidArgumentException(
                "Envelope field '{$field}' must not contain CR, LF, or NUL characters.",
            );
        }
    }
}
