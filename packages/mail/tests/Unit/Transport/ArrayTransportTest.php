<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\Transport\ArrayTransport;

#[CoversClass(ArrayTransport::class)]
final class ArrayTransportTest extends TestCase
{
    #[Test]
    public function send_collects_envelopes(): void
    {
        $transport = new ArrayTransport();
        $envelope = new Envelope(to: ['a@test.com'], from: 'b@test.com', subject: 'Hi');

        $transport->send($envelope);
        $transport->send($envelope);

        $this->assertCount(2, $transport->getSent());
        $this->assertSame($envelope, $transport->getSent()[0]);
    }

    #[Test]
    public function reset_clears_sent(): void
    {
        $transport = new ArrayTransport();
        $transport->send(new Envelope(to: ['a@test.com'], from: 'b@test.com', subject: 'Hi'));
        $transport->reset();

        $this->assertSame([], $transport->getSent());
    }
}
