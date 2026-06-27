<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;

#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function constructs_with_required_fields(): void
    {
        $envelope = new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: 'Test',
        );

        $this->assertSame(['user@example.com'], $envelope->to);
        $this->assertSame('noreply@example.com', $envelope->from);
        $this->assertSame('Test', $envelope->subject);
        $this->assertSame('', $envelope->textBody);
        $this->assertSame('', $envelope->htmlBody);
        $this->assertSame([], $envelope->headers);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $envelope = new Envelope(
            to: ['a@example.com', 'b@example.com'],
            from: 'noreply@example.com',
            subject: 'Hello',
            textBody: 'Plain text',
            htmlBody: '<p>HTML</p>',
            headers: ['X-Custom' => 'value'],
        );

        $this->assertCount(2, $envelope->to);
        $this->assertSame('Plain text', $envelope->textBody);
        $this->assertSame('<p>HTML</p>', $envelope->htmlBody);
        $this->assertSame(['X-Custom' => 'value'], $envelope->headers);
    }

    #[Test]
    public function allows_newlines_in_text_body(): void
    {
        $envelope = new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: 'Test',
            textBody: "Line one\r\nLine two\nLine three",
            htmlBody: "<p>Hello</p>\r\n<p>World</p>",
        );

        $this->assertStringContainsString("\r\n", $envelope->textBody);
        $this->assertStringContainsString("\r\n", $envelope->htmlBody);
    }

    // --- CRLF injection tests (must FAIL before the guard is added) ---

    #[Test]
    public function rejects_crlf_in_subject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/subject/i');

        new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: "Subject\r\nBcc: evil@example.com",
        );
    }

    #[Test]
    public function rejects_bare_cr_in_subject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: "Subject\rEvil",
        );
    }

    #[Test]
    public function rejects_bare_lf_in_subject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: "Subject\nBcc: evil@example.com",
        );
    }

    #[Test]
    public function rejects_crlf_in_from(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/from/i');

        new Envelope(
            to: ['user@example.com'],
            from: "noreply@example.com\r\nBcc: evil@example.com",
            subject: 'Test',
        );
    }

    #[Test]
    public function rejects_crlf_in_to_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/to\[0\]/i');

        new Envelope(
            to: ["user@example.com\r\nBcc: evil@example.com"],
            from: 'noreply@example.com',
            subject: 'Test',
        );
    }

    #[Test]
    public function rejects_crlf_in_header_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/header name/i');

        new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: 'Test',
            headers: ["X-Custom\r\nBcc: evil@example.com" => 'value'],
        );
    }

    #[Test]
    public function rejects_crlf_in_header_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/header value/i');

        new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: 'Test',
            headers: ['X-Custom' => "value\r\nBcc: evil@example.com"],
        );
    }

    #[Test]
    public function rejects_nul_in_subject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Envelope(
            to: ['user@example.com'],
            from: 'noreply@example.com',
            subject: "Subject\0Evil",
        );
    }
}
