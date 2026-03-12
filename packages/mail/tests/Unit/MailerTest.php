<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\Mailer;
use Waaseyaa\Mail\Transport\ArrayTransport;

#[CoversClass(Mailer::class)]
final class MailerTest extends TestCase
{
    #[Test]
    public function send_delegates_to_transport(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport);
        $envelope = new Envelope(to: ['a@test.com'], from: 'b@test.com', subject: 'Hi');

        $mailer->send($envelope);

        $this->assertCount(1, $transport->getSent());
        $this->assertSame('b@test.com', $transport->getSent()[0]->from);
    }

    #[Test]
    public function send_uses_default_from_when_envelope_from_is_empty(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport, defaultFrom: 'default@test.com');
        $envelope = new Envelope(to: ['a@test.com'], from: '', subject: 'Hi');

        $mailer->send($envelope);

        $this->assertSame('default@test.com', $transport->getSent()[0]->from);
    }

    #[Test]
    public function send_preserves_explicit_from_over_default(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport, defaultFrom: 'default@test.com');
        $envelope = new Envelope(to: ['a@test.com'], from: 'explicit@test.com', subject: 'Hi');

        $mailer->send($envelope);

        $this->assertSame('explicit@test.com', $transport->getSent()[0]->from);
    }
}
