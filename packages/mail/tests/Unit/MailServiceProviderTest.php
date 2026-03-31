<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Driver\NullMailDriver;
use Waaseyaa\Mail\Driver\SendGridDriver;
use Waaseyaa\Mail\MailDriverInterface;
use Waaseyaa\Mail\MailServiceProvider;

#[CoversClass(MailServiceProvider::class)]
final class MailServiceProviderTest extends TestCase
{
    #[Test]
    public function register_binds_null_driver_by_default(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', []);
        $provider->register();

        $bindings = $provider->getBindings();
        $this->assertArrayHasKey(MailDriverInterface::class, $bindings);
        $this->assertTrue($bindings[MailDriverInterface::class]['shared']);

        $driver = ($bindings[MailDriverInterface::class]['concrete'])();
        $this->assertInstanceOf(NullMailDriver::class, $driver);
    }

    #[Test]
    public function register_binds_sendgrid_driver_when_api_key_configured(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', [
            'mail' => [
                'sendgrid_api_key' => 'SG.test-key',
                'from_address' => 'test@example.com',
                'from_name' => 'Test',
            ],
        ]);
        $provider->register();

        $bindings = $provider->getBindings();
        $driver = ($bindings[MailDriverInterface::class]['concrete'])();
        $this->assertInstanceOf(SendGridDriver::class, $driver);
    }
}
