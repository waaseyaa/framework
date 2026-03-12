<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Mailer;
use Waaseyaa\Mail\MailerInterface;
use Waaseyaa\Mail\MailServiceProvider;
use Waaseyaa\Mail\Transport\ArrayTransport;
use Waaseyaa\Mail\Transport\TransportInterface;

#[CoversClass(MailServiceProvider::class)]
final class MailServiceProviderTest extends TestCase
{
    #[Test]
    public function register_binds_local_transport_by_default(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', []);
        $provider->register();

        $bindings = $provider->getBindings();
        $this->assertArrayHasKey(TransportInterface::class, $bindings);
        $this->assertTrue($bindings[TransportInterface::class]['shared']);
    }

    #[Test]
    public function register_binds_array_transport_when_configured(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', ['mail' => ['transport' => 'array']]);
        $provider->register();

        $bindings = $provider->getBindings();
        $transport = ($bindings[TransportInterface::class]['concrete'])();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
    }

    #[Test]
    public function register_binds_mailer_interface(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', ['mail' => ['transport' => 'array']]);
        $provider->register();

        $bindings = $provider->getBindings();
        $this->assertArrayHasKey(MailerInterface::class, $bindings);
    }

    #[Test]
    public function mailer_binding_factory_produces_mailer_instance(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', ['mail' => ['transport' => 'array', 'from_address' => 'test@example.com']]);
        $provider->register();

        $bindings = $provider->getBindings();
        $mailer = ($bindings[MailerInterface::class]['concrete'])();
        $this->assertInstanceOf(Mailer::class, $mailer);
    }
}
