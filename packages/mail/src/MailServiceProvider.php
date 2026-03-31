<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mail\Driver\NullMailDriver;
use Waaseyaa\Mail\Driver\SendGridDriver;
use Waaseyaa\Mail\Transport\ArrayTransport;
use Waaseyaa\Mail\Transport\LocalTransport;
use Waaseyaa\Mail\Transport\TransportInterface;

final class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $mailConfig = $this->config['mail'] ?? [];
        $transportType = $mailConfig['transport'] ?? 'local';
        $fromAddress = $mailConfig['from_address'] ?? '';

        $this->singleton(TransportInterface::class, match ($transportType) {
            'array' => fn(): ArrayTransport => new ArrayTransport(),
            'local' => fn(): LocalTransport => new LocalTransport(
                $mailConfig['log_path'] ?? $this->projectRoot . '/var/mail.log',
            ),
            default => fn(): LocalTransport => new LocalTransport(
                $mailConfig['log_path'] ?? $this->projectRoot . '/var/mail.log',
            ),
        });

        $this->singleton(MailerInterface::class, fn(): Mailer => new Mailer(
            transport: $this->resolve(TransportInterface::class),
            defaultFrom: $fromAddress,
        ));

        $sendgridKey = $mailConfig['sendgrid_api_key'] ?? '';
        $fromName = $mailConfig['from_name'] ?? '';

        $this->singleton(MailDriverInterface::class, fn(): MailDriverInterface => match (true) {
            $sendgridKey !== '' => new SendGridDriver($sendgridKey, $fromAddress, $fromName),
            default => new NullMailDriver(),
        });
    }
}
