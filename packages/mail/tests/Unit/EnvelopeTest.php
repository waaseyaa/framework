<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
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
}
