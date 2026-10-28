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
use Waaseyaa\Mail\Transport\SendGridTransport;
use Waaseyaa\Mail\Transport\SmtpTransport;
use Waaseyaa\Mail\Transport\TransportInterface;

#[CoversClass(MailServiceProvider::class)]
final class MailServiceProviderTest extends TestCase
{
    #[Test]
    public function register_binds_local_transport_by_default(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', [], []);
        $provider->register();

        $bindings = $provider->getBindings();
        $this->assertArrayHasKey(TransportInterface::class, $bindings);
        $this->assertTrue($bindings[TransportInterface::class]['shared']);
    }

    #[Test]
    public function register_binds_array_transport_when_configured(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', ['mail' => ['transport' => 'array']], []);
        $provider->register();

        $bindings = $provider->getBindings();
        $transport = ($bindings[TransportInterface::class]['concrete'])();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
    }

    #[Test]
    public function register_binds_mailer_interface(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', ['mail' => ['transport' => 'array']], []);
        $provider->register();

        $bindings = $provider->getBindings();
        $this->assertArrayHasKey(MailerInterface::class, $bindings);
    }

    #[Test]
    public function mailer_binding_factory_produces_mailer_instance(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', ['mail' => ['transport' => 'array', 'from_address' => 'test@example.com']], []);
        $provider->register();

        $bindings = $provider->getBindings();
        $mailer = ($bindings[MailerInterface::class]['concrete'])();
        $this->assertInstanceOf(Mailer::class, $mailer);
    }

    #[Test]
    public function explicit_array_transport_is_not_overridden_by_old_sendgrid_settings(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', [
            'mail' => [
                'transport' => 'array',
                'sendgrid_api_key' => 'sg-key',
                'from_address' => 'noreply@example.com',
            ],
        ], []);
        $provider->register();

        $bindings = $provider->getBindings();
        $transport = ($bindings[TransportInterface::class]['concrete'])();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
    }

    #[Test]
    public function smtp_binding_is_shared_and_uses_the_configured_sender_even_with_an_old_sendgrid_key(): void
    {
        $provider = new MailServiceProvider();
        $config = SmtpTransportTest::configuration();
        $config['sendgrid_api_key'] = 'old-unused-key';
        $provider->setKernelContext('/tmp/test', ['mail' => $config], []);
        $provider->register();
        $transport = $provider->resolve(TransportInterface::class);
        $this->assertInstanceOf(SmtpTransport::class, $transport);
        $this->assertSame($transport, $provider->resolve(TransportInterface::class));
        $this->assertInstanceOf(Mailer::class, $provider->resolve(MailerInterface::class));
    }

    #[Test]
    public function omitted_transport_still_selects_complete_sendgrid_configuration(): void
    {
        $provider = new MailServiceProvider();
        $provider->setKernelContext('/tmp/test', ['mail' => ['sendgrid_api_key' => 'test-key', 'from_address' => 'sender@example.test']], []);
        $provider->register();
        $this->assertInstanceOf(SendGridTransport::class, $provider->resolve(TransportInterface::class));
    }
}
