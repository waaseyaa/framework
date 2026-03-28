<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\MailMessage;

#[CoversClass(MailMessage::class)]
final class MailMessageTest extends TestCase
{
    #[Test]
    public function creates_plain_text_message(): void
    {
        $msg = new MailMessage(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'Test Subject',
            body: 'Hello world',
        );

        $this->assertSame('sender@example.com', $msg->from);
        $this->assertSame('recipient@example.com', $msg->to);
        $this->assertSame('Test Subject', $msg->subject);
        $this->assertSame('Hello world', $msg->body);
        $this->assertSame('', $msg->htmlBody);
        $this->assertSame('', $msg->fromName);
    }

    #[Test]
    public function creates_html_message_with_from_name(): void
    {
        $msg = new MailMessage(
            from: 'sender@example.com',
            to: 'recipient@example.com',
            subject: 'HTML Test',
            body: 'Plain fallback',
            htmlBody: '<h1>Hello</h1>',
            fromName: 'Test App',
        );

        $this->assertSame('<h1>Hello</h1>', $msg->htmlBody);
        $this->assertSame('Test App', $msg->fromName);
    }
}
