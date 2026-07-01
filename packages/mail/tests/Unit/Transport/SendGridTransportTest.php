<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\Transport\SendGridTransport;

#[CoversClass(SendGridTransport::class)]
final class SendGridTransportTest extends TestCase
{
    #[Test]
    public function send_throws_when_api_key_empty(): void
    {
        $transport = new SendGridTransport('', 'from@example.com', 'App');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SendGrid transport is not configured.');
        $transport->send(new Envelope(
            to: ['to@example.com'],
            from: '',
            subject: 'Test',
            textBody: 'Hi',
        ));
    }

    #[Test]
    public function send_throws_when_from_address_empty(): void
    {
        $transport = new SendGridTransport('key', '', 'App');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SendGrid transport is not configured.');
        $transport->send(new Envelope(
            to: ['to@example.com'],
            from: '',
            subject: 'Test',
            textBody: 'Hi',
        ));
    }

    #[Test]
    public function send_throws_when_recipients_empty(): void
    {
        $transport = new SendGridTransport('key', 'from@example.com', 'App');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot send email: no valid recipients provided.');
        $transport->send(new Envelope(
            to: [],
            from: '',
            subject: 'Test',
            textBody: 'Hi',
        ));
    }

    #[Test]
    public function send_throws_when_all_recipients_blank(): void
    {
        $transport = new SendGridTransport('key', 'from@example.com', 'App');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot send email: no valid recipients provided.');
        $transport->send(new Envelope(
            to: ['', '  '],
            from: '',
            subject: 'Test',
            textBody: 'Hi',
        ));
    }
}
