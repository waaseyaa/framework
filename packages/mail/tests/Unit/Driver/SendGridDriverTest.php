<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Driver\SendGridDriver;
use Waaseyaa\Mail\MailMessage;

#[CoversClass(SendGridDriver::class)]
final class SendGridDriverTest extends TestCase
{
    #[Test]
    public function is_not_configured_with_empty_api_key(): void
    {
        $driver = new SendGridDriver('', 'from@example.com', 'App');
        $this->assertFalse($driver->isConfigured());
    }

    #[Test]
    public function is_not_configured_with_empty_from_address(): void
    {
        $driver = new SendGridDriver('key', '', 'App');
        $this->assertFalse($driver->isConfigured());
    }

    #[Test]
    public function is_configured_with_all_values(): void
    {
        $driver = new SendGridDriver('key', 'from@example.com', 'App');
        $this->assertTrue($driver->isConfigured());
    }

    #[Test]
    public function send_throws_when_not_configured(): void
    {
        $driver = new SendGridDriver('', '', '');
        $msg = new MailMessage(
            from: 'a@b.com',
            to: 'c@d.com',
            subject: 'Test',
            body: 'Hello',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mail driver is not configured');
        $driver->send($msg);
    }
}
