<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mail\Transport\ArrayTransport;
use Waaseyaa\Mail\Transport\LocalTransport;
use Waaseyaa\Mail\Transport\SendGridTransport;
use Waaseyaa\Mail\Transport\SmtpTransport;
use Waaseyaa\Mail\Transport\TransportInterface;

final class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $mailConfig = $this->config['mail'] ?? [];
        $configuration = MailConfiguration::fromArray($mailConfig);
        $fromAddress = $configuration->fromAddress;

        $this->singleton(TransportInterface::class, fn(): TransportInterface => match ($configuration->transport) {
            'smtp' => SmtpTransport::fromConfiguration($configuration),
            'sendgrid' => new SendGridTransport(
                apiKey: $configuration->sendgridKey,
                fromAddress: $fromAddress,
                fromName: $configuration->fromName,
            ),
            'array' => new ArrayTransport(),
            default => new LocalTransport(
                $mailConfig['log_path'] ?? $this->projectRoot . '/var/mail.log',
            ),
        });

        $this->singleton(MailerInterface::class, fn(): Mailer => new Mailer(
            transport: $this->resolve(TransportInterface::class),
            defaultFrom: $fromAddress,
        ));
    }
}
